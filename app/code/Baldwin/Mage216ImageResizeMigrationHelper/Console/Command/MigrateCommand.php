<?php

namespace Baldwin\Mage216ImageResizeMigrationHelper\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Model\Product\Media\Config as MediaConfig; // not using interface, because Magento 2.1.5 hasn't got this one defined in the di.xml file
use Magento\Framework\App\Area;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\State as AppState;
use Magento\Framework\Encryption\Encryptor;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Filesystem;
use Magento\Framework\View\ConfigInterface as ViewConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Theme\Model\ResourceModel\Theme\Collection as ThemeCollection;

// SOME DOCUMENTATION
// - Before running this command, make sure you are on Magento 2.1.4 or 2.1.5 and you have run `bin/magento catalog:images:resize` first!
// - Then run `bin/magento catalog:image:baldwin:migrate-to-216` (preferably using the symlink-new-to-old option)
// - After this is done, you can upgrade Magento to version 2.1.6 (not to a later version!, as we don't know what Magento will change in later versions)
// - Now test your shop, hopefully all images are still visible
// - You can now remove all old files (TODO: create a new command for this)
// - Finally, run `bin/magento catalog:images:resize` again, just to be sure all images in the 2.1.6 format are certainly being generated

// UNTESTED:
// - images on multiple storeviews
// - watermarks
// - special image manipulations: rotation, different background colors, aspect_ratio, frame, transparency, constrain

// ROADMAP:
// - include a fix for @airdrumz reported issue with hidden images used on the product listing not being generated:
//   https://github.com/magento/magento2/issues/9276#issuecomment-295691637
// - include a fix for problems 1 & 2 reported here: https://github.com/magento/magento2/issues/8145, so the `bin/magento catalog:images:resize` runs much quicker
//   (instead of removing `image_type` type param, we should hardcode it to 'thumbnail' for example, this avoid backwards compatible issues, the same problem which was reponsible for the existance of this very own module)
// - see if we can try to re-enable the behavior of resizing images on the frontend

class MigrateCommand extends Command
{
    const COMMAND_NAME = 'catalog:image:baldwin:migrate-to-216';

    const STRATEGY_COPY               = 1;
    const STRATEGY_SYMLINK_OLD_TO_NEW = 2;
    const STRATEGY_SYMLINK_NEW_TO_OLD = 3;

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
        // TODO: verify that this command is being run from Magento 2.1.4 or 2.1.5 only, otherwise abort

        $this->appState->setAreaCode('catalog');

        $mapping = $this->mapOldVsNewFilepaths();
        $this->migrateOldVsNewFilepaths($mapping);
        $this->displayMigrationSummary($mapping, $output);

        $output->writeln("<info>DONE</info>");
    }

    private function getStrategy()
    {
        // TODO: get from cli input argument
        return self::STRATEGY_SYMLINK_NEW_TO_OLD;
    }

    /**
     * This method figures out how the old pre-Magento 2.1.6 filepaths are mapped against the Magento 2.1.6 filepaths
     */
    private function mapOldVsNewFilepaths()
    {
        $mapping = [];

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
            $newFilePath = $this->getNewFilepath($imageData);

            // print_r($imageData);
            // var_dump($newFilePath);

            $mapping[] = [
                'imageData' => $imageData,
                'oldPath'   => $oldFilePath,
                'newPath'   => $newFilePath,
            ];
        }

        return $mapping;
    }

    /**
     * This method copies or symlinks the old to the new filepaths
     */
    private function migrateOldVsNewFilepaths(array &$mapping)
    {
        foreach ($mapping as $key => $line)
        {
            $status = '<error>Skipped for an unknown reason</error>';

            $oldPath = $line['oldPath'];
            $newPath = $line['newPath'];

            if (!is_dir($oldPath))
            {
                $status = '<comment>Old path doesn\'t exist or isn\'t a directory, skipping...</comment>';
            }
            else if (is_link($oldPath))
            {
                $status = '<comment>Old path is already a symlink, skipping...</comment>';
            }
            else
            {
                if (file_exists($newPath) || is_link($newPath))
                {
                    $status = '<question>New path already exists, skipping...</question>';
                }
                else
                {
                    switch ($this->getStrategy())
                    {
                        case self::STRATEGY_COPY:
                            // not the fastest option, but is probably the safest one if you aren't sure
                            // TODO: maybe use http://symfony.com/doc/2.8/components/filesystem.html#mirror ?
                        break;
                        case self::STRATEGY_SYMLINK_OLD_TO_NEW:
                            // only usefull for testing
                            // just symlink old to new

                            $success = symlink($oldPath, $newPath);
                            if ($success)
                            {
                                $status = '<info>Successfully symlinked old path to new path</info>';
                            }
                            else
                            {
                                $status = '<error>Something went wrong while trying to symlink old path to new path</error>';
                            }
                        break;
                        case self::STRATEGY_SYMLINK_NEW_TO_OLD:
                            // should be used on production
                            // first move old directories to new directories
                            // then symlink new to old

                            $moveSuccess = rename($oldPath, $newPath);
                            if ($moveSuccess)
                            {
                                $linkSuccess = symlink($newPath, $oldPath);
                                if ($linkSuccess)
                                {
                                    $status = '<info>Successfully first moved old path to new path and then symlinked new path to old path</info>';
                                }
                                else
                                {
                                    // yikes, let's try to revert what we have already done
                                    $reverseMoveSuccess = rename($newPath, $oldPath);
                                    if ($reverseMoveSuccess)
                                    {
                                        $status = '<error>Symlink from new path to old path didn\'t work out, we reverted everything to its initial state</error>';
                                    }
                                    else
                                    {
                                        $status = '<error>Yikes! Something went horribly wrong, we renamed old path to new path, tried to create a symlink from new path to old path, this failed, then we tried to rename new path to old path again and this failed to, this isn\'t good!</error>';
                                    }
                                }
                            }
                            else
                            {
                                $status = '<error>Something went wrong while trying to rename old path to new path</error>';
                            }
                        break;
                    }
                }
            }

            $mapping[$key]['status'] = $status;
        }
    }

    /**
     * This method displays a summary of what was done
     */
    private function displayMigrationSummary(array $mapping, OutputInterface $output)
    {
        $headers = ['Old path', 'New Path', 'Status'];
        $rows = [];

        foreach ($mapping as $line)
        {
            $oldPath = str_replace($this->getImageContextPath(), '', $line['oldPath']);
            $newPath = str_replace($this->getImageContextPath(), '', $line['newPath']);
            $status  = $line['status'];
            $rows[] = [$oldPath, $newPath, $status];
        }

        $output->writeln('');
        $output->writeln('Base directory: <info>' . $this->getImageContextPath() . '</info>');
        $output->writeln('');

        $table = new Table($output);
        $table
            ->setHeaders($headers)
            ->setRows($rows)
        ;

        $table->render();
    }

    // copied some stuff from Magento 2.1.5's Magento\Catalog\Model\Product\Image::setBaseFile
    private function getOldFilepath(array $imageData)
    {
        $type = isset($imageData['type']) ? $imageData['type'] : null;

        // build new filename (most important params)
        $path = [
            $this->getImageContextPath(),
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

        return $path;
    }

    // copied some stuff from Magento 2.1.6's Magento\Catalog\Model\View\AssetImage class
    private function getNewFilepath(array $imageData)
    {
        $miscParams = $this->buildMiscParams($imageData);
        $miscPath = $this->encryptor->hash(implode('_', $miscParams), Encryptor::HASH_VERSION_MD5);

        $result = $this->getImageContextPath();
        $result = $this->join($result, 'cache');
        $result = $this->join($result, $miscPath);
        // $result = $this->join($result, $filePath);

        $path = DIRECTORY_SEPARATOR . $result;

        return $path;
    }

    // based on Magento 2.1.6's Magento\Catalog\Model\View\Asset\Image\Context::getPath, which doesn't exist in 2.1.5
    private function getImageContextPath()
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
