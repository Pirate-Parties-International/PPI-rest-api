<?php
namespace AppBundle\Service;

use Doctrine\ORM\EntityManager;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Config\Definition\Exception\Exception;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Validator\Constraints\DateTime;

use AppBundle\Command\ScraperCommand;
use AppBundle\Service\ScraperServices;

class GoogleService extends ScraperServices
{
    protected $em;
    protected $images;
    protected $parent;
    private   $container;

    public function __construct(EntityManager $entityManager, Container $container) {
        $this->em        = $entityManager;
        $this->container = $container;
        $this->parent    = $this->container->get('ScraperServices');
        $this->images    = $this->container->get('ImageService');
        @set_exception_handler(array($parent, 'exception_handler'));
    }


    /**
     * Queries Youtube for stats and videos
     * @param  string $googleId
     * @param  string $partyCode
     * @return array
     */
    public function getYoutubeData($googleId, $partyCode) {
        $youtube = $this->container
            ->get('ConnectionService')
            ->getNewGoogle($googleId, true);
        $data = $youtube->getChannelByName($googleId);

        echo "     + Info and stats... ";
        if (empty($data)) {
            echo "not found\n";
            return false;
        }
        if (empty($data->statistics) || empty($data->statistics->viewCount)) {
            echo "not found\n";
            return false;
        }

        // these stats are strings, so we need to cast them to int to save them to db
        $out['stats']['viewCount']       = (int)$data->statistics->viewCount;
        $out['stats']['subscriberCount'] = (int)$data->statistics->subscriberCount;
        $out['stats']['videoCount']      = (int)$data->statistics->videoCount;

        $playlist = $data->contentDetails->relatedPlaylists->uploads;
        $videos   = $youtube->getPlaylistItemsByPlaylistId($playlist);

        echo "     + Videos... ";
        if (!empty($videos)) {
            $out['videos'] = $this->getVideoDetails($partyCode, $youtube, $videos);
            echo "processed\n";
        } else {
            echo "not found\n";
        }

        return $out;
    }


    /**
     * Retrieves video details
     * @param  string $partyCode
     * @param  object $youtube
     * @param  array  $videos
     * @return array
     */
    public function getVideoDetails($partyCode, $youtube, $videos) {
        $out = [];

        foreach ($videos as $key => $vid) {

            $vidId   = $vid->snippet->resourceId->videoId;
            $vidInfo = $youtube->getVideoInfo($vidId);

            $imgSrc  = $vid->snippet->thumbnails->medium->url; // 320x180 (only 16:9 option)
            // deafult=120x90, medium=320x180, high=480x360, standard=640x480, maxres=1280x720

            $img = $this->images->saveImage('yt', $partyCode, $imgSrc, $vidId);

            $vidTime = \DateTime::createFromFormat('Y-m-d\TH:i:s.u\Z', $vid->snippet->publishedAt);
            // original ISO 8601, e.g. '2015-04-30T21:45:59.000Z'

            $vidLikes = isset($vidInfo->statistics->likeCount)    ? $vidInfo->statistics->likeCount    : null;
            $vidViews = isset($vidInfo->statistics->viewCount)    ? $vidInfo->statistics->viewCount    : null;
            $vidComms = isset($vidInfo->statistics->commentCount) ? $vidInfo->statistics->commentCount : null;

            $out[] = [
                'postId'    => $vidId,
                'postTime'  => $vidTime, // DateTime
                'postText'  => $vid->snippet->title,
                'postImage' => $img,
                'postLikes' => $vidLikes,
                'postData'  => [
                    'id'          => $vidId,
                    'posted'      => $vidTime->format('Y-m-d H:i:s'), // string
                    'text'        => $vid->snippet->title,
                    'description' => $vid->snippet->description,
                    'image'       => $img,
                    'img_source'  => $imgSrc,
                    'url'         => 'https://www.youtube.com/watch?v='.$vidId,
                    'views'       => $vidViews,
                    'likes'       => $vidLikes,
                    'comments'    => $vidComms
                    ]
                ];
        }

        return $out;
    }


    /**
     * Queries Google+ for followers
     * @param  string $googleId
     * @return int
     */
    public function getGooglePlusData($googleId) {
        $data = $this->container
            ->get('ConnectionService')
            ->getNewGoogle($googleId);

        if (empty($data) || !isset($data->circledByCount)) {
            return false;
        }

        return $data->circledByCount;
    }

}