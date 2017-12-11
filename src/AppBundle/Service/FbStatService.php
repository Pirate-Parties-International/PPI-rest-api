<?php
namespace AppBundle\Service;

use Doctrine\ORM\EntityManager;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Config\Definition\Exception\Exception;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Validator\Constraints\DateTime;

use AppBundle\Command\ScraperCommand;

class FbStatService
{
    private   $container;
    protected $connect;
    protected $db;
    protected $images;

    public function __construct(Container $container) {
        $this->container = $container;
        $this->connect   = $this->container->get('ConnectionService');
        $this->db        = $this->container->get('DatabaseService');
        $this->images    = $this->container->get('ImageService');
        @set_exception_handler([$this->db, 'exception_handler']);
    }


    /**
     * Basic info about a FB page
     * @param  object $fb
     * @param  string $fbPageId
     * @param  stting $requestFields
     * @return array
     */
    public function getPageInfo($fb, $fbPageId, $requestFields) {
        $graphNode = $this->connect->getFbGraphNode($fb, $fbPageId, $requestFields);

        echo "     + Info and stats.... ";
        if (empty($graphNode)) {
            echo "not found\n";
            return false;
        }
        // var_dump($graphNode); exit;

        $out['info'] = [
            'about'   => $graphNode->getField('about'),
            'address' => $graphNode->getField('single_line_address')
        ];

        $fdEmails = $graphNode->getField('emails');
        if (!empty($fdEmails)) {
            foreach ($fdEmails as $key => $email) {
                $out['info']['email'][] = $email;
            }
        }

        $coverId = !empty($graphNode->getField('cover')) ? $graphNode->getField('cover')->getField('cover_id') : null;
        $out['cover'] = !is_null($coverId) ? $this->images->getFbImageSource($fb, $coverId, true) : null;

        $out['likes']   = !empty($graphNode->getField('engagement')) ? $graphNode->getField('engagement')->getField('count') : null;
        $out['talking'] = !empty($graphNode->getField('talking_about_count')) ? $graphNode->getField('talking_about_count') : null;

        echo "ok\n";

        return $out;
    }


    /**
     * Post count for stats only
     * @param  object $fb
     * @param  string $fbPageId
     * @param  string $requestFields
     * @return int
     */
    public function getPostCount($fb, $fbPageId, $requestFields) {
        $graphNode = $this->connect->getFbGraphNode($fb, $fbPageId, $requestFields);

        echo "     + Counting posts.... ";
        if (empty($graphNode) || !$graphNode->getField('posts')) {
            echo "not found\n";
            return false;
        }
        // var_dump($graphNode); exit;

        $fdPcount  = $graphNode->getField('posts');
        echo "page ";
        $pageCount = 0;

        do {
            echo $pageCount . ', ';
            foreach ($fdPcount as $key => $post) {
                $temp['posts'][] = ['id' => $post->getField('id')]; // count all posts
            }
            $pageCount++;
        } while ($fdPcount = $fb->next($fdPcount)); // while next page is not null

        $out['postCount'] = count($temp['posts']);
        echo "...total " . $out['postCount'] . " found\n";

        return $out['postCount'];
    }


    /**
     * Image count for stats only
     * @param  object $fb
     * @param  string $fbPageId
     * @param  string $requestFields
     * @return int
     */
    public function getImageCount($fb, $fbPageId, $requestFields) {
        $graphNode = $this->connect->getFbGraphNode($fb, $fbPageId, $requestFields);

        echo "     + Counting photos... ";
        if (empty($graphNode) || !$graphNode->getField('albums')) {
            echo "not found\n";
            return false;
        }
        // var_dump($graphNode); exit;

        $fdAlbums  = $graphNode->getField('albums');
        echo "page ";
        $pageCount = 0;

        foreach ($fdAlbums as $key => $album) {
            echo $pageCount . ", ";
            $photoCount[] = $album->getField('photo_count');
            $pageCount++;
        }

        $out['imageCount'] = array_sum($photoCount);
        echo "...total " . $out['imageCount'] . " found\n";

        return $out['imageCount'];
    }


    /**
     * Video count for stats only
     * @param  object $fb
     * @param  string $fbPageId
     * @param  string $requestFields
     * @return int
     */
    public function getVideoCount($fb, $fbPageId, $requestFields) {
        $graphNode = $this->connect->getFbGraphNode($fb, $fbPageId, $requestFields);

        echo "     + Counting videos... ";
        if (empty($graphNode) || !$graphNode->getField('videos')) {
            echo "not found\n";
            return false;
        }
        // var_dump($graphNode); exit;

        $fdVcount = $graphNode->getField('videos');
        echo "page ";
        $pageCount = 0;

        do {
            echo $pageCount . ', ';
            foreach ($fdVcount as $key => $post) {
                $temp['videos'][] = ['id' => $post->getField('id')]; // count all posts
            }
            $pageCount++;
        } while ($fdVcount = $fb->next($fdVcount)); // while next page is not null

        $out['videoCount'] = count($temp['videos']);
        echo "...total " . $out['videoCount'] . " found\n";

        return $out['videoCount'];
    }


    /**
     * Event count for stats only
     * @param  object $fb
     * @param  string $fbPageId
     * @param  string $requestFields
     * @return int
     */
    public function getEventCount($fb, $fbPageId, $requestFields) {
        $graphNode = $this->connect->getFbGraphNode($fb, $fbPageId, $requestFields);

        echo "     + Counting events... ";
        if (empty($graphNode) || !$graphNode->getField('events')) {
            echo "not found.\n";
            return false;
        }
        // var_dump($graphNode); exit;

        $fdEvents  = $graphNode->getField('events');
        echo "page ";
        $pageCount = 0;

        do {
            echo $pageCount . ", ";
            foreach ($fdEvents as $key => $event) {
                $temp['events'][] = ['id' => $event->getField('id')];
            }
            $pageCount++;
        } while ($fdEvents = $fb->next($fdEvents)); // while next page is not null

        $out['eventCount'] = count($temp['events']);
        echo "...total " . $out['eventCount'] . " found\n";

        return $out['eventCount'];
    }


    /**
    * Counts likes/reactions/comments etc.
    * @param  object $data
    * @return int
    */
    public function getStatCount($data) {
        if (!empty($data)) {
            $meta  = $data->getMetadata();
            $count = isset($meta['summary']['total_count']) ? $meta['summary']['total_count'] : null;
        } else {
            $count = null;
        }

        return $count;
    }
}