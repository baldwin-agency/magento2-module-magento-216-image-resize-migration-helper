<?php

namespace Baldwin\Mage216ImageResizeMigrationHelper\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use Magento\Framework\App\Area;
use Magento\Framework\Filesystem;
use Magento\Store\Model\ScopeInterface;
use Magento\Framework\Encryption\Encryptor;
use Magento\Framework\App\State as AppState;
use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Catalog\Model\Product\Media\Config as MediaConfig; // not using interface, because Magento 2.1.5 hasn't got this one defined in the di.xml file
use Magento\Framework\View\ConfigInterface as ViewConfigInterface;
use Magento\Theme\Model\ResourceModel\Theme\Collection as ThemeCollection;

class MigrateCommand extends Command
{
    const COMMAND_NAME = 'catalog:image:baldwin-migrate';

    private $appState;
    private $mediaConfig;
    private $themeCollection;
    private $encryptor;
    private $viewConfig;
    private $scopeConfig;
    private $mediaDirectory;

    public function __construct(
        AppState             $appState,
        Filesystem           $filesystem,
        MediaConfig          $mediaConfig,
        ThemeCollection      $themeCollection,
        EncryptorInterface   $encryptor,
        ViewConfigInterface  $viewConfig,
        ScopeConfigInterface $scopeConfig
    ) {
        $this->appState        = $appState;
        $this->mediaConfig     = $mediaConfig;
        $this->themeCollection = $themeCollection;
        $this->encryptor       = $encryptor;
        $this->viewConfig      = $viewConfig;
        $this->scopeConfig     = $scopeConfig;
        $this->mediaDirectory  = $filesystem->getDirectoryWrite(DirectoryList::MEDIA);
        $this->mediaDirectory->create($this->mediaConfig->getBaseMediaPath());

        parent::__construct();
    }

    protected function configure()
    {
        $this->setName(self::COMMAND_NAME)
            ->setDescription('Migrate cached resized image files from a pre-Magento 2.1.6 filestructure to the Magento 2.1.6 filestructure [BALDWIN]')
            // ->setDefinition()
        ;

        // TODO: check if image storage is on filesystem, and not in database
        // TODO: add option for symlinking or hard copying
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->appState->setAreaCode('catalog');

        $this->mapOldVsNewFilepaths();
        $this->migrateOldVsNewFilepaths();
        $this->displayMigrationSummary();

        $output->writeln("<info>DONE</info>");
    }

    /**
     * This method figures out how the old pre-Magento 2.1.6 filepaths are mapped against the Magento 2.1.6 filepaths
     */
    private function mapOldVsNewFilepaths()
    {
        $imageDataArray = [];

        foreach ($this->themeCollection->loadRegisteredThemes() as $theme)
        {
            $config = $this->viewConfig->getViewConfig([
                'area' => Area::AREA_FRONTEND,
                'themeModel' => $theme,
            ]);
            $images = $config->getMediaEntities('Magento_Catalog', ImageHelper::MEDIA_TYPE_CONFIG_NODE);
            foreach ($images as $imageId => $imageData)
            {
                $data = $imageData;
                // $data = array_merge(['id' => $imageId], $imageData); // the 'id' param isn't used in M2.1.6 calculations, so skip it for now
                $imageDataArray[sha1(json_encode($data))] = $data; // using hash of data as way to make sure we don't have any duplicates in here
            }
        }

        foreach ($imageDataArray as $imageData)
        {
            $oldFilePath = $this->getOldFilepath($imageData);
            // $newFilePath = $this->getNewFilepath($imageData);

            // print_r($imageData);
            // var_dump($newFilePath);
        }

        var_dump(count($imageDataArray));
    }

    /**
     * This method copies or symlinks the old to the new filepaths
     */
    private function migrateOldVsNewFilepaths()
    {

    }

    /**
     * This method displays a summary of what was done
     */
    private function displayMigrationSummary()
    {

    }

    // copied some stuff from Magento 2.1.5's Magento\Catalog\Model\Product\Image::setBaseFile
    private function getOldFilepath($imageData)
    {
        $type = isset($imageData['type']) ? $imageData['type'] : null;

        // build new filename (most important params)
        $path = [
            $this->mediaConfig->getBaseMediaPath(),
            'cache',
            $type, // used to be getDestinationSubdir, but that should be the same as 'type'
        ];
        if (!empty($imageData['width']) || !empty($imageData['height'])) {
            $path[] = "{$imageData['width']}x{$imageData['height']}";
        }

        // add misk params as a hash
        $miscParams = [
            (empty($imageData['aspect_ratio']) ? '' : 'non') . 'proportional',
            (empty($imageData['frame']) ? '' : 'no') . 'frame',
            (empty($imageData['transparency']) ? '' : 'no') . 'transparency',
            (empty($imageData['constrain']) ? 'do' : 'not') . 'constrainonly',
            $this->rgbToString((!empty($imageData['background']) ? $imageData['background'] : [255, 255, 255])),
            'angle' . (!empty($imageData['angle']) ? $imageData['angle'] : null),
            'quality' . 80,
        ];

        // if has watermark add watermark params to hash
        $watermarkFile = $this->scopeConfig->getValue(
            "design/watermark/{$type}_image",
            ScopeInterface::SCOPE_STORE
        );

        if ($watermarkFile) {
            $watermarkSize = $this->scopeConfig->getValue(
                "design/watermark/{$type}_size",
                ScopeInterface::SCOPE_STORE
            );

            $miscParams['watermark_file'] = $watermarkFile;
            $miscParams['watermark_image_opacity'] = $this->scopeConfig->getValue(
                "design/watermark/{$type}_imageOpacity",
                ScopeInterface::SCOPE_STORE
            );
            $miscParams['watermark_position'] = $this->scopeConfig->getValue(
                "design/watermark/{$type}_position",
                ScopeInterface::SCOPE_STORE
            );
            $miscParams['watermark_width'] = !empty($watermarkSize['width']) ? $watermarkSize['width'] : null;
            $miscParams['watermark_height'] = !empty($watermarkSize['width']) ? $watermarkSize['height'] : null;
        }

        $path[] = md5(implode('_', $miscParams));

        $path = implode('/', $path);

        var_dump($path);

        return $path;
    }

    // copied some stuff from Magento 2.1.6's Magento\Catalog\Model\View\AssetImage class
    private function getNewFilepath($imageData)
    {
        $miscParams = $this->buildMiscParams($imageData);
        $miscPath = $this->encryptor->hash(implode('_', $miscParams), Encryptor::HASH_VERSION_MD5);

        $result = $this->getContextPath();
        $result = $this->join($result, 'cache');
        $result = $this->join($result, $miscPath);
        // $result = $this->join($result, $filePath);

        $path = DIRECTORY_SEPARATOR . $result;

        return $path;
    }

    // based on Magento 2.1.6's Magento\Catalog\Model\View\Asset\Image\Context::getPath, which doesn't exist in 2.1.5
    private function getContextPath()
    {
        return $this->mediaDirectory->getAbsolutePath($this->mediaConfig->getBaseMediaPath());
    }

    // copy from Magento 2.1.6's Magento\Catalog\Model\View\Asset\Image::join
    private function join($path, $item)
    {
        return trim(
            $path . ($item ? DIRECTORY_SEPARATOR . ltrim($item, DIRECTORY_SEPARATOR) : ''),
            DIRECTORY_SEPARATOR
        );
    }

    // copy from Magento 2.1.6's Magento\Catalog\Model\Product\Image\ParamsBuilder::build
    private function buildMiscParams(array $imageArguments)
    {
        $type = isset($imageArguments['type']) ? $imageArguments['type'] : null;

        $width = isset($imageArguments['width']) ? $imageArguments['width'] : null;
        $height = isset($imageArguments['height']) ? $imageArguments['height'] : null;

        $frame = !empty($imageArguments['frame'])
            ? $imageArguments['frame']
            : true;

        $constrain = !empty($imageArguments['constrain'])
            ? $imageArguments['constrain']
            : true;

        $aspectRatio = !empty($imageArguments['aspect_ratio'])
            ? $imageArguments['aspect_ratio']
            : true;

        $transparency = !empty($imageArguments['transparency'])
            ? $imageArguments['transparency']
            : true;

        $background = !empty($imageArguments['background'])
            ? $imageArguments['background']
            : [255, 255, 255];

        $miscParams = [
            'image_type' => $type,
            'image_height' => $height,
            'image_width' => $width,
            'keep_aspect_ratio' => ($aspectRatio ? '' : 'non') . 'proportional',
            'keep_frame' => ($frame ? '' : 'no') . 'frame',
            'keep_transparency' => ($transparency ? '' : 'no') . 'transparency',
            'constrain_only' => ($constrain ? 'do' : 'not') . 'constrainonly',
            'background' => $this->rgbToString($background),
            'angle' => !empty($imageArguments['angle']) ? $imageArguments['angle'] : null,
            'quality' => 80
        ];

        $watermarkFile = $this->scopeConfig->getValue(
            "design/watermark/{$type}_image",
            ScopeInterface::SCOPE_STORE
        );

        if ($watermarkFile) {
            $watermarkSize = $this->scopeConfig->getValue(
                "design/watermark/{$type}_size",
                ScopeInterface::SCOPE_STORE
            );

            $miscParams['watermark_file'] = $watermarkFile;
            $miscParams['watermark_image_opacity'] = $this->scopeConfig->getValue(
                "design/watermark/{$type}_imageOpacity",
                ScopeInterface::SCOPE_STORE
            );
            $miscParams['watermark_position'] = $this->scopeConfig->getValue(
                "design/watermark/{$type}_position",
                ScopeInterface::SCOPE_STORE
            );
            $miscParams['watermark_width'] = !empty($watermarkSize['width']) ? $watermarkSize['width'] : null;
            $miscParams['watermark_height'] = !empty($watermarkSize['width']) ? $watermarkSize['height'] : null;
        }

        return $miscParams;
    }

    // copy from Magento 2.1.6's Magento\Catalog\Model\Product\Image\ParamsBuilder::rgbToString
    // this is also 100% the same as Magento's 2.1.5 Magento\Catalog\Model\Product\Image::_rgbToString
    public function rgbToString($rgbArray)
    {
        $result = [];
        foreach ($rgbArray as $value) {
            if (null === $value) {
                $result[] = 'null';
            } else {
                $result[] = sprintf('%02s', dechex($value));
            }
        }
        return implode($result);
    }
}
