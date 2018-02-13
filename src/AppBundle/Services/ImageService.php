<?php
namespace AppBundle\Services;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Config\Definition\Exception\Exception;
use Symfony\Component\DependencyInjection\Container;

use AppBundle\Command\ScraperCommand;

class ImageService
{
    private   $container;
    protected $log;
    protected $connect;
    protected $db;

    public function __construct(Container $container) {
        $this->container = $container;
        $this->log       = $this->container->get('logger');
        $this->connect   = $this->container->get('ConnectionService');
        $this->db        = $this->container->get('DatabaseService');
        @set_exception_handler(array($this->connect, 'exception_handler'));
    }


    /**
     * Saves uploaded images to disk
     * @param  string $site
     * @param  string $code
     * @param  string $imgSrc
     * @param  string $imgId
     * @param  string $imgBkp
     * @return string
     */
    public function saveImage($site, $code, $imgSrc, $imgId, $imgBkp = null) {
        $appRoot = $this->container->get('kernel')->getRootDir() . '/..';
        $imgRoot = $appRoot . '/web/img/uploads/' . $code . '/' . $site . '/';
        preg_match('/.+\.(png|jpg)/i', $imgSrc, $matches);

        if (empty($matches)) {
            return null;
        }

        $imgFmt  = $matches[1];
        $imgName = $imgId . '.' . $imgFmt;
        $imgPath = $imgRoot . $imgName;

        if (!is_dir($imgRoot)) { // check if directory exists, else create
            mkdir($imgRoot, 0755, true);
        }

        if (!file_exists($imgPath)) { // check if file exists on disk before saving
            try {
                $imgData = $this->connect->curl($imgSrc);
            } catch (\Exception $e) {
                $this->log->notice($e->getMessage());
                if ($imgBkp) { // try backup if available
                    try {
                        $imgData = $this->connect->curl($imgBkp);
                        $this->log->info("    + Backup successful");
                    } catch (\Exception $e) {
                        $this->log->notice("    - Backup unsuccessful");
                    }
                }
            }
        }

        if (!empty($imgData)) {
            try {
                file_put_contents($imgPath, $imgData);
            } catch (\Exception $e) {
                $this->log->notice($e->getMessage());
            }
        }

        return $imgName;
    }


    /**
     * Retrieves Facebook covers and saves them to disk
     * @param  string $partyCode
     * @param  string $imgSrc
     * @return string       local relative path
     */
    public function getFacebookCover($partyCode, $imgSrc) {
        $appRoot = $this->container->get('kernel')->getRootDir() . '/..';
        $imgRoot = $appRoot . '/web/img/fb-covers/';

        if (!is_dir($imgRoot)) {
            mkdir($imgRoot, 0755, true);
        }

        preg_match('/.+\.(png|jpg)/i', $imgSrc, $matches);
        $imgFmt = $matches[1];

        try {
            $imgData = $this->connect->curl($imgSrc);
        } catch (\Exception $e) {
            $this->log->notice($e->getMessage());
        }

        if (empty($imgData)) {
            return false;
        }

        $imgName = strtolower($partyCode) . '.' . $imgFmt;
        $imgPath = $imgRoot . $imgName;
        file_put_contents($imgPath, $imgData);

        $this->cropFbCover($imgPath);

        return '/img/fb-covers/'.$imgName;
    }


    /**
     * Crops Facebook cover images
     * @param  string $path
     * @return null
     */
    public function cropFbCover($path) {
        $crop_width  = 851;
        $crop_height = 315;

        $image    = new \Imagick($path);
        $geometry = $image->getImageGeometry();

        $width  = $geometry['width'];
        $height = $geometry['height'];

        if ($width == $crop_width && $height == $crop_height) {
            return;
        }
        if ($width < $crop_width && $height < $crop_height) {
            return;
        }

        // First we scale
        $x_index = ($width / $crop_width);
        $y_index = ($height / $crop_height);

        if ($x_index > 1 && $y_index > 1) {
            $image->scaleImage($crop_width, 0);
        }

        $geometry = $image->getImageGeometry();
        if ($geometry['height'] > 351) {
            $image->cropImage($crop_width, $crop_height, 0, 0);
        }

        file_put_contents($path, $image);
    }


    /**
     * Finds best resolution of an image
     * @param  object $fb
     * @param  string $imgId
     * @param  bool   $cover
     * @return string
     */
    public function getFbImageSource($imgId, $cover = false) {
        $graphNode = $this->container
            ->get('ConnectionService')
            ->getFbGraphNode($imgId, 'height,width,album,images');

        if (empty($graphNode) || !$graphNode->getField('images')) {
            return false;
        }

        $images = $graphNode->getField('images');

        if (!$cover) {
            foreach ($images as $key => $img) {
                if ($img->getField('width') < 481 && $img->getField('height') < 481) {
                    return $img->getField('source'); // get biggest available up to 480x480
                }
            }
            return $img->getField('source'); // if above fails, just get whatever's available
        }

        $tmpI = [];
        $tmpA = [];

        foreach ($images as $key => $img) {
            if ($img->getField('width') == 851 && $img->getField('height') == 351) {
                return $img->getField('source');
            } else if ($img->getField('width') > 851 && $img->getField('height') > 351) {
                $tmpI[$img->getField('width') + $img->getField('height')] = $img->getField('source');
            } else {
                $tmpA[$img->getField('width') + $img->getField('height')] = $img->getField('source');
            }
        }

        if (!empty($tmpI)) {
            $t   = max(array_keys($tmpI));
            $img = $tmpI[$t];
        } else {
            $t   = max(array_keys($tmpA));
            $img = $tmpA[$t];
        }

        return $img;
    }


    /**
     * Decodes Facebook external urls to find an image's source where available
     * @param  object $post
     * @return array
     */
    public function getFbExtImageSource($post) {
        $data = null;
        $type = $post->getField('type');

        if ($type == 'video') {
            $data['type'] = 'video';
            $link = urldecode($post->getField('link'));

            if (strpos($link, 'youtu')) { // youtube.com or youtu.be
                if (strpos($link, 'v=')) {
                    $idPosition = strpos($link, 'v=')+2;
                } else if (strpos($link, 'youtu.be/')) {
                    $idPosition = strpos($link, '.be/')+4;
                }
                $vidId  = substr($link, $idPosition, 11);

                $data['src'] = "https://img.youtube.com/vi/" . $vidId . "/mqdefault.jpg";
                // default=120x90, mqdefault=320x180, hqdefault=480x360, sddefault=640x480
                $data['bkp'] = $post->getField('picture');
                return $data;
            }

            $data['src'] = $post->getField('picture');
            $data['bkp'] = null;
            return $data;
        }

        $data['type'] = 'post';
        $data['src']  = $post->getField('picture') ? $post->getField('picture') : null;
        $data['bkp']  = null;

        if ($data['src'] && strpos($data['src'], 'external.xx.fbcdn.net')) {
            $start = strpos($data['src'], '&url=')+5;
            $temp  = substr($data['src'], $start);
            $end   = strpos($temp, '&');
            $temp  = substr($temp, 0, $end);
            $data['src'] = urldecode($temp);
        }

        return $data;
    }
}