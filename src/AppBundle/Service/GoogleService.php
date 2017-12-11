<?php
namespace AppBundle\Service;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Config\Definition\Exception\Exception;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Validator\Constraints\DateTime;

use AppBundle\Command\ScraperCommand;
use AppBundle\Entity\SocialMedia;

class GoogleService
{
    private   $container;
    protected $db;
    protected $images;

    public function __construct(Container $container) {
        $this->container = $container;
        $this->db        = $this->container->get('DatabaseService');
        $this->images    = $this->container->get('ImageService');
        @set_exception_handler(array($db, 'exception_handler'));
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

        echo "     + Info and stats... ";
        if (!$youtube) {
            echo "not found\n";
            return false;
        }

        $data = $youtube->getChannelByName($googleId);
        if (empty($data) || empty($data->statistics) || empty($data->statistics->viewCount)) {
            echo "not found\n";
            return false;
        }

        // these stats are strings, so we need to cast them to int to save them to db
        $out['stats']['viewCount']       = (int)$data->statistics->viewCount;
        $out['stats']['subscriberCount'] = (int)$data->statistics->subscriberCount;
        $out['stats']['videoCount']      = (int)$data->statistics->videoCount;

        echo "ok\n";

        $playlist = $data->contentDetails->relatedPlaylists->uploads;
        $videos   = $youtube->getPlaylistItemsByPlaylistId($playlist);
        $vidCount = 0;

        echo "     + Videos... ";
        if (empty($videos)) {
            echo "not found\n";
            return $out;
        }

        foreach ($videos as $key => $vid) {
            $this->getVideoDetails($partyCode, $youtube, $vid);
            $vidCount++;
        }
        echo $vidCount . " found and processed\n";
        $out['videos'] = $vidCount;

        return $out;
    }


    /**
     * Retrieves video details
     * @param  string $partyCode
     * @param  object $youtube
     * @param  array  $vid
     * @return array
     */
    public function getVideoDetails($partyCode, $youtube, $vid) {
        $vidId   = $vid->snippet->resourceId->videoId;
        $vidTime = \DateTime::createFromFormat('Y-m-d\TH:i:s.u\Z', $vid->snippet->publishedAt);
        // original ISO 8601, e.g. '2015-04-30T21:45:59.000Z'

        $imgSrc  = $vid->snippet->thumbnails->medium->url;
        // deafult=120x90, medium=320x180, high=480x360, standard=640x480, maxres=1280x720
        $img     = $this->images->saveImage('yt', $partyCode, $imgSrc, $vidId);

        $vidInfo  = $youtube->getVideoInfo($vidId);
        $vidLikes = isset($vidInfo->statistics->likeCount)    ? $vidInfo->statistics->likeCount    : null;
        $vidViews = isset($vidInfo->statistics->viewCount)    ? $vidInfo->statistics->viewCount    : null;
        $vidComms = isset($vidInfo->statistics->commentCount) ? $vidInfo->statistics->commentCount : null;

        $data = [
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
            ];

        $this->db->addSocial(
            $partyCode,
            SocialMedia::TYPE_YOUTUBE,
            SocialMedia::SUBTYPE_VIDEO,
            $vidId,
            $vidTime,
            $vid->snippet->title,
            $img,
            $vidLikes,
            $data
        );
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