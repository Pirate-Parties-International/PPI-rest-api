<?php
namespace AppBundle\Services\Facebook;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Config\Definition\Exception\Exception;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Console\Output\OutputInterface;

use AppBundle\Command\ScraperCommand;
use AppBundle\Entity\SocialMedia;
use AppBundle\Entity\Statistic;

class FbPostService extends FacebookService
{
    private   $container;
    protected $log;
    protected $connect;
    protected $db;
    protected $images;

    public function __construct(Container $container) {
        $this->container = $container;
        $this->log       = $this->container->get('logger');
        $this->connect   = $this->container->get('ConnectionService');
        $this->db        = $this->container->get('DatabaseService');
        $this->images    = $this->container->get('ImageService');
        @set_exception_handler([$this->connect, 'exception_handler']);
    }


    /**
     * Processes text posts (inc. videos)
     * @param  string $partyCode
     * @param  string $fbPageId
     * @param  object $fb
     * @param  bool   $scrapeFull
     * @return array
     */
    public function getPosts($partyCode, $fbPageId, $fb, $scrapeFull = false) {
        $requestFields = 'posts.limit(50){id,type,permalink_url,message,story,link,name,caption,picture,object_id,created_time,updated_time,shares,likes.limit(0).summary(true),reactions.limit(0).summary(true),comments.limit(0).summary(true)}';
        $graphNode     = $this->connect->getFbGraphNode($fbPageId, $requestFields);

        if (empty($graphNode) || is_null($graphNode->getField('posts'))) {
            $this->log->notice("    - Facebook posts not found for " . $partyCode);
            return false;
        }

        $this->log->info("    + Getting post details...");
        $fdPosts   = $graphNode->getField('posts');

        $pageCount = 0;
        $txtCount  = 0;
        $vidCount  = 0;
        $loopCount = 0;
        $temp      = [];

        $timeLimit = $this->db->getTimeLimit($partyCode, 'fb', 'T', $scrapeFull);

        do {
            $this->log->debug("       + Page " . $pageCount);

            foreach ($fdPosts as $key => $post) {
                $id = $post->getField('id');

                if (in_array($id, $temp, true)) {
                    // if post was already scraped this session
                    $loopCount++;
                    continue;
                }

                $type = $post->getField('type');
                // types = 'status', 'link', 'photo', 'video', 'event'

                if ($type == 'photo' || $type == 'event') {
                    continue; // get photos and events separately to get all details
                } else if ($type == 'video') {
                    $subType = SocialMedia::SUBTYPE_VIDEO;
                    $vidCount++;
                } else {
                    $subType = SocialMedia::SUBTYPE_TEXT;
                    $txtCount++;
                }

                $temp[$id] = $this->getPostDetails($partyCode, $post, $subType);
            }

            $timeCheck = $post->getField('created_time')->getTimestamp(); // check time of last scraped post
            $pageCount++;

        } while ($timeCheck > $timeLimit && $fdPosts = $fb->next($fdPosts));
        // while next page is not null and within our time limit

        if ($loopCount > 0) {
            $this->log->warning("     - Facebook post scraping for " . $partyCode . " looped " . $loopCount . " times");
        }

        $this->db->processSocialMedia($temp);

        $out['posts']  = $txtCount;
        $out['videos'] = $vidCount;
        $this->log->info("      + " . $txtCount . " text posts and " . $vidCount . " videos since " . date('d/m/Y', $timeCheck) . " processed");

        return (isset($out)) ? $out : null;
    }


    /**
     * Retrieves the details of a text post or video
     * @param  string $partyCode
     * @param  object $post
     * @param  string $subType
     * @return null
     */
    public function getPostDetails($partyCode, $post, $subType) {
        $text   = !empty($post->getField('message')) ? $post->getField('message') : $post->getField('story');
        $imgSrc = $this->images->getFbExtImageSource($post);
        $img    = isset($imgSrc['src']) ? $this->images->saveImage('fb', $partyCode, $imgSrc['src'], $post->getField('id'), $imgSrc['bkp']) :  null;

        $likeCount     = $this->getStatCount($post->getField('likes'));
        $reactionCount = $this->getStatCount($post->getField('reactions'));
        $commentCount  = $this->getStatCount($post->getField('comments'));
        $shareCount    = !empty($post->getField('shares')) ? json_decode($post->getField('shares')->getField('count'), true) : null;

        $allData = [
            'id'         => $post->getField('id'),
            'posted'     => $post->getField('created_time')->format('Y-m-d H:i:s'), // string
            'updated'    => $post->getField('updated_time')->format('Y-m-d H:i:s'), // string
            'text'       => $text,
            'image'      => $img,
            'img_source' => $imgSrc['src'],
            'link'       => [
                'url'        => $post->getField('link'),
                'name'       => $post->getField('name'),
                'caption'    => $post->getField('caption'),
                ],
            'url'        => $post->getField('permalink_url'),
            'likes'      => $likeCount,
            'reactions'  => $reactionCount,
            'comments'   => $commentCount,
            'shares'     => $shareCount
            ];

        $out = [
            'code'    => $partyCode,
            'type'    => SocialMedia::TYPE_FACEBOOK,
            'subtype' => $subType,
            'id'      => $post->getField('id'),
            'time'    => $post->getField('updated_time'), // DateTime
            'text'    => $text,
            'img'     => $img,
            'likes'   => $reactionCount,
            'allData' => $allData
            ];

        return $out;
    }


    /**
     * Post count for stats only
     * @param  string $partyCode
     * @param  string $fbPageId
     * @param  object $fb
     * @param  bool   $scrapeFull
     * @return int
     */
    public function getPostCount($partyCode, $fbPageId, $fb, $scrapeFull = false) {
        $requestFields = 'posts{id,created_time}';
        $graphNode     = $this->connect->getFbGraphNode($fbPageId, $requestFields);

        if (empty($graphNode) || is_null($graphNode->getField('posts'))) {
            $this->log->notice("    - Error while counting Facebook text posts for " . $partyCode);
            return false;
        }
        // var_dump($graphNode); exit;

        $fdPcount = $graphNode->getField('posts');
        if (empty($fdPcount)) {
            $this->log->notice("    - Error while counting Facebook text posts for " . $partyCode);
            return false;
        }

        $this->log->info("    + Counting text posts...");
        $oldCount  = $this->db->getStatLimit($partyCode, 'fb', 'T', $scrapeFull);
        $pageCount = 0;
        $loopCount = 0;
        $temp      = [];

        do {
            $this->log->debug("       + Page " . $pageCount);

            foreach ($fdPcount as $key => $post) {
                $timeCheck = $post->getField('created_time')->getTimestamp(); // check time of last scraped post

                if (in_array($post->getField('id'), $temp, true)) {
                    // if post was already counted this session
                    $loopCount++;
                    continue;
                }

                if ($timeCheck > $oldCount['time']) {
                    $temp['posts'][] = $post->getField('id');
                }
            }

            $pageCount++;

        } while ($timeCheck > $oldCount['time'] && $fdPcount = $fb->next($fdPcount)); // while next page is not null

        if ($loopCount > 0) {
            $this->log->warning("     - Facebook post counting for " . $partyCode . " looped " . $loopCount . " times");
        }

        $postCount  = isset($temp['posts']) ? count($temp['posts']) : 0;
        $totalCount = $oldCount['value'] + $postCount;

        if ($totalCount == 0) {
            return false;
        }

        $this->db->addStatistic(
            $partyCode,
            Statistic::TYPE_FACEBOOK,
            Statistic::SUBTYPE_POSTS,
            $totalCount
        );

        $this->log->debug("       + " . $postCount . " new text posts found");
        $this->log->info("      + Total " . $totalCount . " text posts to date");
        return true;
    }


    /**
     * Video count for stats only
     * @param  string $partyCode
     * @param  string $fbPageId
     * @param  object $fb
     * @return int
     */
    public function getVideoCount($partyCode, $fbPageId, $fb) {
        $requestFields = 'videos{id}';
        $graphNode     = $this->connect->getFbGraphNode($fbPageId, $requestFields);

        if (empty($graphNode) || is_null($graphNode->getField('videos'))) {
            $this->log->notice("    - Error while counting Facebook videos for " . $partyCode);
            return false;
        }
        // var_dump($graphNode); exit;

        $this->log->info("    + Counting videos...");
        $fdVcount  = $graphNode->getField('videos');
        $pageCount = 0;
        $loopCount = 0;
        $temp      = [];

        do {
            $this->log->debug("       + Page " . $pageCount);
            foreach ($fdVcount as $key => $post) {
                if (in_array($post->getField('id'), $temp, true)) {
                    // if video was already counted this session
                    $loopCount++;
                    continue;
                }
                $temp['videos'][] = $post->getField('id');
            }
            $pageCount++;
        } while ($fdVcount = $fb->next($fdVcount)); // while next page is not null

        if ($loopCount > 0) {
            $this->log->warning("     - Facebook video counting for " . $partyCode . " looped " . $loopCount . " times");
        }

        $videoCount = isset($temp['videos']) ? count($temp['videos']) : 0;
        if ($videoCount == 0) {
            return false;
        }

        $this->db->addStatistic(
            $partyCode,
            Statistic::TYPE_FACEBOOK,
            Statistic::SUBTYPE_VIDEOS,
            $videoCount
        );

        $this->log->info("      + Total " . $videoCount . " videos found");
        return true;
    }

}