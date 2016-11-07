<?php
namespace AppBundle\Extensions;

use Doctrine\ORM\EntityManager;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Config\Definition\Exception\Exception;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Validator\Constraints\DateTime;

use AppBundle\Command\ScraperCommand;
use AppBundle\Extensions\ScraperServices;

use Facebook\Facebook;
use Facebook\FacebookSDKException;
use Facebook\FacebookResponseException;

class FacebookService extends ScraperServices
{
    protected $em;
    private   $container;

    public function __construct(EntityManager $entityManager, Container $container) {
        $this->em = $entityManager;
        $this->container = $container;
        @set_exception_handler(array($scraper, 'exception_handler'));
    }


    /**
    * Counts likes/reactions/comments etc.
    * @param  string $data
    * @return int
    */
    public function getStatCount($data) {
        if (!empty($data)) {
            $meta  = $data->getMetadata();
            $count = isset($meta['summary']['total_count']) ? $meta['summary']['total_count'] : 0;
        } else {
            $count = 0;
        }

        return $count;
    }


    /**
     * Retrives facebook covers and saves them to disk
     * @param  string $code
     * @param  string $imgSrc
     * @return string       local relative path
     */
    public function getFacebookCover($code, $imgSrc) {
        $appRoot = $this->container->get('kernel')->getRootDir().'/..';
        $imgRoot = $appRoot.'/web/img/fb-covers/';

        if (!is_dir($imgRoot)) {
            mkdir($imgRoot, 0755, true);
        }

        preg_match('/.+\.(png|jpg)/i', $imgSrc, $matches);
        $imgFmt = $matches[1];

        $ctx = stream_context_create(array(
            'http' => array(
                'timeout' => 5
                )
            )
        );

        try {
            $imgData = file_get_contents($imgSrc, false, $ctx);
        } catch (\Exception $e) {
            echo $e->getMessage()."\n";
            $out['errors'][] = [$code => $e->getMessage()];
        }

        if (!empty($imgData)) {
            $imgName = strtolower($code).'.'.$imgFmt;
            $imgPath = $imgRoot.$imgName;
            file_put_contents($imgPath, $imgData);

            $this->cropImage($imgPath);

            return '/img/fb-covers/'.$imgName;

        } else {
            return false;
        }
    }


    /**
     * Crops cover images
     * @param  string $path
     * @return null
     */
    public function cropImage($path) {
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
     * Queries Facebook for stats, posts, images and events
     * @param  string $fbPageId
     * @param  string $what     type of data being scraped
     * @param  string $code     party code
     * @return array
     */
    public function getFBData($fbPageId, $what, $code, $full = null) {
        $fb = new Facebook([
            'app_id' => $this->container->getParameter('fb_app_id'),
            'app_secret' => $this->container->getParameter('fb_app_secret'),
            'default_graph_version' => 'v2.7',
        ]);
        $fb->setDefaultAccessToken($this->container->getParameter('fb_access_token'));

        $req = [
            'basic'    => 'cover,engagement,talking_about_count,about,emails,single_line_address',
            'pStats'   => 'posts.limit(100){id}',
            'iStats'   => 'albums{count}',
            'vStats'   => 'videos.limit(100){id}',
            'eStats'   => 'events.limit(100){id}',
            'pDetails' => 'posts.limit(50){id,type,permalink_url,message,story,link,name,caption,picture,object_id,created_time,updated_time,shares,likes.limit(0).summary(true),reactions.limit(0).summary(true),comments.limit(0).summary(true)}',
            'iDetails' => 'albums{count,photos{created_time,updated_time,picture,source,link,name,likes.limit(0).summary(true),reactions.limit(0).summary(true),comments.limit(0).summary(true),sharedposts.limit(0).summary(true)}}',
            'eDetails' => 'events{start_time,updated_time,name,cover,description,place,attending_count,interested_count,comments.limit(0).summary(true)}'
        ];

    //
    // Basic page info and stats
    //
        if ($what == null || $what == 'info') {

            $request = $fb->request('GET', $fbPageId, ['fields' => $req['basic']]);
            try {
                $response = $fb->getClient()->sendRequest($request);
            } catch(Facebook\Exceptions\FacebookResponseException $e) {
                // When Graph returns an error
                echo 'Graph returned an error: ' . $e->getMessage() . "\n";
                exit;
            } catch(Facebook\Exceptions\FacebookSDKException $e) {
                // When validation fails or other local issues
                echo 'Facebook SDK returned an error: ' . $e->getMessage() . "\n";
                exit;
            } catch(\Exception $e) {
                echo $fbPageId . " - Exception: " . $e->getMessage() . "\n";
                return false;
            }
            $graphNode = $response->getGraphNode();

            echo "     + Info and stats.... ";
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
            $out['cover'] = !is_null($coverId) ? $this->getCoverSource($fbPageId, $fb, $coverId) : null;

            $out['likes']   = !empty($graphNode->getField('engagement')) ? $graphNode->getField('engagement')->getField('count') : '?';
            $out['talking'] = !empty($graphNode->getField('talking_about_count')) ? $graphNode->getField('talking_about_count') : '?';

            if ($out['likes'] == '?') {
                echo "not found";
            } else echo "ok";
            echo "\n";

            $out['postCount']  = $this->getPostCount($fbPageId, $fb, $req);
            $out['photoCount'] = $this->getImageCount($fbPageId, $fb, $req);
            $out['videoCount'] = $this->getVideoCount($fbPageId, $fb, $req);
            $out['eventCount'] = $this->getEventCount($fbPageId, $fb, $req);
        }

        if ($what == null || $what == 'posts') {
            $temp = $this->getPostDetails($fbPageId, $fb, $req, $code, $what, $full);
            if (isset($temp['posts'])) {
                $out['posts']  = $temp['posts'];
            }
            if (isset($temp['photos'])) {
                $out['photos'] = $temp['photos'];
            }
            if (isset($temp['videos'])) {
                $out['videos'] = $temp['videos'];
            }
        }

        if ($what == null || $what == 'events') {
            $temp['events'] = $this->getEventDetails($fbPageId, $fb, $req, $code, $what, $full);
            if (isset($temp['events'])) {
                $out['events'] = $temp['events'];
            }
        }

        return $out;
    }


    //
    // Second step for cover images
    //
    public function getCoverSource($fbPageId, $fb, $coverId) {
        $request = $fb->request('GET', $coverId, ['fields' => 'height,width,album,images']);
        try {
            $response = $fb->getClient()->sendRequest($request);
        } catch(Facebook\Exceptions\FacebookResponseException $e) {
            // When Graph returns an error
            echo 'Graph returned an error: ' . $e->getMessage() . "\n";
            exit;
        } catch(Facebook\Exceptions\FacebookSDKException $e) {
            // When validation fails or other local issues
            echo 'Facebook SDK returned an error: ' . $e->getMessage() . "\n";
            exit;
        } catch(\Exception $e) {
            echo $fbPageId . " - Exception: " . $e->getMessage() . "\n";
            return false;
        }
        $graphNode = $response->getGraphNode();
        $images    = $graphNode->getField('images');

        $tmpI = [];
        $tmpA = [];
        foreach ($images as $key => $img) {
            if ($img->getField('width') == 851 && $img->getField('height') == 351) {
                $out['cover'] = $img->getField('source');
                return $out;
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


    //
    // Post count
    //
    public function getPostCount($fbPageId, $fb, $req) {
        $request = $fb->request('GET', $fbPageId, ['fields' => $req['pStats']]);
        try {
            $response = $fb->getClient()->sendRequest($request);
        } catch(Facebook\Exceptions\FacebookResponseException $e) {
            // When Graph returns an error
            echo 'Graph returned an error: ' . $e->getMessage() . "\n";
            exit;
        } catch(Facebook\Exceptions\FacebookSDKException $e) {
            // When validation fails or other local issues
            echo 'Facebook SDK returned an error: ' . $e->getMessage() . "\n";
            exit;
        } catch(\Exception $e) {
            echo $fbPageId . " - Exception: " . $e->getMessage() . "\n";
            return false;
        }
        $graphNode = $response->getGraphNode();
        $fdPcount  = $graphNode->getField('posts');

        if (!empty($fdPcount)) {
            echo "     + Counting posts.... page ";
            $pageCount = 0;

            do {
                echo $pageCount.', ';

                foreach ($fdPcount as $key => $post) {
                    $temp['posts'][] = ['id' => $post->getField('id')]; // count all posts
                }

                $pageCount++;

            } while ($fdPcount = $fb->next($fdPcount));
            // while next page is not null

            $out['postCount'] = count($temp['posts']);
            echo "...total ". $out['postCount'] ." found";

        } else {
            echo "     - Posts not found";
            $out['postCount'] = 0;
        }

        echo "\n";
        return $out['postCount'];
    }


    //
    // Image count
    //
    public function getImageCount($fbPageId, $fb, $req) {
        $request = $fb->request('GET', $fbPageId, ['fields' => $req['iStats']]);
        try {
            $response = $fb->getClient()->sendRequest($request);
        } catch(Facebook\Exceptions\FacebookResponseException $e) {
            // When Graph returns an error
            echo 'Graph returned an error: ' . $e->getMessage() . "\n";
            exit;
        } catch(Facebook\Exceptions\FacebookSDKException $e) {
            // When validation fails or other local issues
            echo 'Facebook SDK returned an error: ' . $e->getMessage() . "\n";
            exit;
        } catch(\Exception $e) {
            echo $fbPageId . " - Exception: " . $e->getMessage() . "\n";
            return false;
        }
        $graphNode = $response->getGraphNode();
        $fdAlbums  = $graphNode->getField('albums');

        if (!empty($fdAlbums)) {
            echo "     + Counting photos... page ";
            $pageCount = 0;

            foreach ($fdAlbums as $key => $album) {
                echo $pageCount.", ";
                $photoCount[] = $album->getField('count');
                $pageCount++;
            }

            $out['photoCount'] = array_sum($photoCount);
            echo "...total ". $out['photoCount'] ." found";

        } else {
            echo "     - Photos not found";
            $out['photoCount'] = 0;
        }

        echo "\n";
        return $out['photoCount'];
    }


    //
    // Video count
    //
    public function getVideoCount($fbPageId, $fb, $req) {
        $request = $fb->request('GET', $fbPageId, ['fields' => $req['vStats']]);
        try {
            $response = $fb->getClient()->sendRequest($request);
        } catch(Facebook\Exceptions\FacebookResponseException $e) {
            // When Graph returns an error
            echo 'Graph returned an error: ' . $e->getMessage() . "\n";
            exit;
        } catch(Facebook\Exceptions\FacebookSDKException $e) {
            // When validation fails or other local issues
            echo 'Facebook SDK returned an error: ' . $e->getMessage() . "\n";
            exit;
        } catch(\Exception $e) {
            echo $fbPageId . " - Exception: " . $e->getMessage() . "\n";
            return false;
        }
        $graphNode = $response->getGraphNode();
        $fdVcount  = $graphNode->getField('videos');

        if (!empty($fdVcount)) {
            echo "     + Counting videos... page ";
            $pageCount = 0;

            do {
                echo $pageCount.', ';

                foreach ($fdVcount as $key => $post) {
                    $temp['videos'][] = ['id' => $post->getField('id')]; // count all posts
                }

                $pageCount++;

            } while ($fdVcount = $fb->next($fdVcount));
            // while next page is not null

            $out['videoCount'] = count($temp['videos']);
            echo "...total ". $out['videoCount'] ." found";

        } else {
            echo "     - Videos not found";
            $out['videoCount'] = 0;
        }

        echo "\n";
        return $out['videoCount'];
    }


    //
    // Event count
    //
    public function getEventCount($fbPageId, $fb, $req) {
        $request = $fb->request('GET', $fbPageId, ['fields' => $req['eStats']]);
        try {
            $response = $fb->getClient()->sendRequest($request);
        } catch(Facebook\Exceptions\FacebookResponseException $e) {
            // When Graph returns an error
            echo 'Graph returned an error: ' . $e->getMessage() . "\n";
            exit;
        } catch(Facebook\Exceptions\FacebookSDKException $e) {
            // When validation fails or other local issues
            echo 'Facebook SDK returned an error: ' . $e->getMessage() . "\n";
            exit;
        } catch(\Exception $e) {
            echo $fbPageId . " - Exception: " . $e->getMessage() . "\n";
            return false;
        }
        $graphNode = $response->getGraphNode();
        $fdEvents  = $graphNode->getField('events');

        if (!empty($fdEvents)) {
            echo "     + Counting events... page ";
            $pageCount = 0;
            do {
                echo $pageCount.", ";
                foreach ($fdEvents as $key => $event) {
                    $temp['events'][] = ['id' => $event->getField('id')];
                }
                $pageCount++;
            } while ($fdEvents = $fb->next($fdEvents));
            // while next page is not null

            $out['eventCount'] = count($temp['events']);
            echo "...total ". $out['eventCount'] ." found";

        } else {
            echo "     - Events not found";
            $out['eventCount'] = 0;
        }

        echo "\n";
        return $out['eventCount'];
    }


    //
    // Post, image and video details
    //
    public function getPostDetails($fbPageId, $fb, $req, $code, $what, $full) {
        $scraper = $this->container->get('ScraperServices');

        $request = $fb->request('GET', $fbPageId, ['fields' => $req['pDetails']]);
        try {
            $response = $fb->getClient()->sendRequest($request);
        } catch(Facebook\Exceptions\FacebookResponseException $e) {
            // When Graph returns an error
            echo 'Graph returned an error: ' . $e->getMessage() . "\n";
            exit;
        } catch(Facebook\Exceptions\FacebookSDKException $e) {
            // When validation fails or other local issues
            echo 'Facebook SDK returned an error: ' . $e->getMessage() . "\n";
            exit;
        } catch(\Exception $e) {
            echo $fbPageId . " - Exception: " . $e->getMessage() . "\n";
            return false;
        }
        $graphNode = $response->getGraphNode();
        $fdPosts   = $graphNode->getField('posts');

        echo "     + Post details.... ";
        if (!empty($fdPosts)) {

            $timeLimit = $scraper->getTimeLimit('fb', $code, $what, $full);
            echo "page ";
            $pageCount = 0;

            do {
                echo $pageCount .', ';

                foreach ($fdPosts as $key => $post) {

                    $type = $post->getField('type'); // types = 'status', 'link', 'photo', 'video', 'event'
                    if ($type != 'event') { // get events separately to get all details (location, etc.)

                        $img = null;
                        if ($type == 'photo' || $type == 'video') {
                            $postType = $type.'s';
                            $imgSrc = $post->getField('picture'); // 130x130 thumbnail
                            $img = $scraper->saveImage('fb', $code, $imgSrc, $post->getField('id'));
                        } else $postType = 'posts';

                        $text = !empty($post->getField('message')) ? $post->getField('message') : $post->getField('story');

                        $likeCount     = $this->getStatCount($post->getField('likes'));
                        $reactionCount = $this->getStatCount($post->getField('reactions'));
                        $commentCount  = $this->getStatCount($post->getField('comments'));
                        $shareCount    = !empty($post->getField('shares')) ? json_decode($post->getField('shares')->getField('count'), true) : 0;

                        $out[$postType][] = [
                            'postId'    => $post->getField('id'),
                            'postTime'  => $post->getField('updated_time'), // DateTime
                            'postText'  => $text,
                            'postImage' => $img,
                            'postLikes' => $reactionCount,
                            'postData'  => [
                                'id'        => $post->getField('id'),
                                'posted'    => $post->getField('created_time')->format('Y-m-d H:i:s'), // string
                                'updated'   => $post->getField('updated_time')->format('Y-m-d H:i:s'), // string
                                'message'   => $post->getField('message'), // main body of text
                                'story'     => $post->getField('story'),   // "[page] shared a link", etc.
                                'link'      => [
                                    'url'       => $post->getField('link'),
                                    'name'      => $post->getField('name'),
                                    'caption'   => $post->getField('caption'),
                                    'thumb'     => $post->getField('picture')
                                    ],
                                'url'       => $post->getField('permalink_url'),
                                'likes'     => $likeCount,
                                'reactions' => $reactionCount,
                                'comments'  => $commentCount,
                                'shares'    => $shareCount
                                ],
                            ];
                    }
                }

                $timeCheck = $post->getField('created_time')->getTimestamp(); // check time of last scraped post
                $pageCount++;

            } while ($timeCheck > $timeLimit && $fdPosts = $fb->next($fdPosts));
            // while next page is not null and within our time limit

            $txtCount = array_key_exists('posts',  $out) ? count($out['posts'])  : 0;
            $imgCount = array_key_exists('photos', $out) ? count($out['photos']) : 0;
            $vidCount = array_key_exists('videos', $out) ? count($out['videos']) : 0;
            echo "...".$txtCount." text posts, ".$imgCount." images and ".$vidCount." videos since ".date('d/m/Y', $timeCheck)." processed";

        } else {
            echo "not found";
        }
        echo "\n";
        return $out;
    }



    //
    // Event details
    //
    public function getEventDetails($fbPageId, $fb, $req, $code, $what, $full) {
        $scraper = $this->container->get('ScraperServices');

        $request = $fb->request('GET', $fbPageId, ['fields' => $req['eDetails']]);
        try {
            $response = $fb->getClient()->sendRequest($request);
        } catch(Facebook\Exceptions\FacebookResponseException $e) {
            // When Graph returns an error
            echo 'Graph returned an error: ' . $e->getMessage() . "\n";
            exit;
        } catch(Facebook\Exceptions\FacebookSDKException $e) {
            // When validation fails or other local issues
            echo 'Facebook SDK returned an error: ' . $e->getMessage() . "\n";
            exit;
        } catch(\Exception $e) {
            echo $fbPageId . " - Exception: " . $e->getMessage() . "\n";
            return false;
        }
        $graphNode = $response->getGraphNode();
        $fdEvents  = $graphNode->getField('events');
        echo "     + Event details... ";

        if (!empty($fdEvents)) {

            $timeLimit = $scraper->getTimeLimit('fb', $code, $what, $full);
            echo "page ";
            $pageCount = 0;

            do { // process current page of results
                echo $pageCount .', ';
                if (!empty($fdEvents)) {
                    foreach ($fdEvents as $key => $event) {

                        $place = $event->getField('place');
                        if (!empty($place)) { // must be checked in advance, will break if null
                            $placeName = $place->getField('name');
                            $location  = $place->getField('location');
                        } else $placeName = null;

                        if (!empty($location)) { // must be checked in advance, will break if null
                            $placeAddress = [
                                'street'    => $location->getField('street'),
                                'city'      => $location->getField('city'),
                                'zip'       => $location->getField('zip'),
                                'country'   => $location->getField('country'),
                                'longitude' => $location->getField('longitude'),
                                'latitude'  => $location->getField('latitude')
                                ];
                        } else $placeAddress = null;

                        $commentCount = $this->getStatCount($event->getField('comments'));
                        $coverData    = json_decode($event->getField('cover'), true);

                        $imgId  = $coverData['id'];
                        $imgSrc = $coverData['source'];

                        // save cover image to disk
                        if (!empty($imgSrc)) {
                            $img = $scraper->saveImage('fb', $code, $imgSrc, $imgId);
                        } else {
                            $img = null;
                        }

                        $out['events'][] = [
                            'postId'    => $event->getField('id'),
                            'postTime'  => $event->getField('updated_time'), // DateTime
                            'postText'  => $event->getField('name'),
                            'postImage' => $img,
                            'postLikes' => $event->getField('interested_count'),
                            'postData'  => [
                                'id'         => $event->getField('id'),
                                'start_time' => $event->getField('start_time')->format('Y-m-d H:i:s'), // string
                                'updated'    => $event->getField('updated_time')->format('Y-m-d H:i:s'), // string
                                'name'       => $event->getField('name'),
                                'details'    => [
                                    'description' => $event->getField('description'),
                                    'place'       => $placeName,
                                    'address'     => $placeAddress,
                                    'cover'       => [
                                        'id'          => $imgId,
                                        'source'      => $imgSrc
                                        ]
                                    ],
                                'url'        => 'https://www.facebook.com/events/'.$event->getField('id'),
                                'attending'  => $event->getField('attending_count'),
                                'interested' => $event->getField('interested_count'),
                                'comments'   => $commentCount
                                ]
                            ];
                    }

                    $timeCheck = $event->getField('updated_time')->getTimestamp(); // check time of last scraped post
                    $pageCount++;
                }

            } while ($timeCheck > $timeLimit && $fdEvents = $fb->next($fdEvents));
            // while next page is not null and within our time limit

            echo "...".count($out['events'])." found and processed";

        } else {
            echo "not found";
        }

        echo "\n";
        return isset($out['events']) ? $out['events'] : null;
    }

}