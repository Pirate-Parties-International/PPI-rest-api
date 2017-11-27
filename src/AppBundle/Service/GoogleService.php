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

use Madcoda\Youtube;

class GoogleService extends ScraperServices
{
    protected $em;
    private   $container;

    public function __construct(EntityManager $entityManager, Container $container) {
        $this->em = $entityManager;
        $this->container = $container;
        @set_exception_handler(array($scraper, 'exception_handler'));
    }


    /**
     * Queries Youtube for stats and videos
     * @param  string $googleId
     * @param  string $partyCode
     * @return array
     */
    public function getYoutubeData($googleId, $partyCode) {
    	$scraper = $this->container->get('ScraperServices');

        $apikey  = $this->container->getParameter('gplus_api_key');
        $youtube = new Youtube(array('key' => $apikey));

        $data = $youtube->getChannelByName($googleId);

        if (empty($data)) {
            return false;
        }
        if (empty($data->statistics) || empty($data->statistics->viewCount)) {
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
            $out['videos'] = [];
            foreach ($videos as $key => $vid) {

                $vidId   = $vid->snippet->resourceId->videoId;
                $vidInfo = $youtube->getVideoInfo($vidId);
                $imgSrc  = $vid->snippet->thumbnails->medium->url; // 320x180 (only 16:9 option)
                // deafult=120x90, medium=320x180, high=480x360, standard=640x480, maxres=1280x720

                // save thumbnail to disk
                $img = $scraper->saveImage('yt', $partyCode, $imgSrc, $vidId);

                $vidTime = \DateTime::createFromFormat('Y-m-d\TH:i:s.u\Z', $vid->snippet->publishedAt);
                // original ISO 8601, e.g. '2015-04-30T21:45:59.000Z'

                $vidLikes = isset($vidInfo->statistics->likeCount)    ? $vidInfo->statistics->likeCount    : null;
                $vidViews = isset($vidInfo->statistics->viewCount)    ? $vidInfo->statistics->viewCount    : null;
                $vidComms = isset($vidInfo->statistics->commentCount) ? $vidInfo->statistics->commentCount : null;

                $out['videos'][] = [
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
            echo "processed\n";

        } else {
            echo "not found\n";
        }

        return $out;

    }


    /**
     * Queries Google+ for followers
     * @param  string $googleId
     * @return int
     */
    public function getGooglePlusData($googleId) {
    	$scraper = $this->container->get('ScraperServices');

        $apikey = $this->container->getParameter('gplus_api_key');
        $google = $scraper->curl(
            sprintf('https://www.googleapis.com/plus/v1/people/%s?key=%s',
                $googleId, $apikey)
            );
        $data = json_decode( $google );
        if (empty($data) || !isset($data->circledByCount)) {
            return false;
        }
        return $data->circledByCount;

    }

}