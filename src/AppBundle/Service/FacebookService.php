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


//
// Getting info
//

    /**
     * Queries for stats, posts, images and events
     * @param  string $fbPageId
     * @param  string $what     type of data being scraped
     * @param  string $code     party code
     * @param  bool   $full     if user requested full scrape
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
            'iDetails' => 'albums{id,name,photo_count,photos{created_time,updated_time,picture,source,link,name,likes.limit(0).summary(true),reactions.limit(0).summary(true),comments.limit(0).summary(true),sharedposts.limit(0).summary(true)}}',
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
            $out['cover'] = !is_null($coverId) ? $this->getImageSource($fbPageId, $fb, $coverId, true) : null;

            $out['likes']   = !empty($graphNode->getField('engagement')) ? $graphNode->getField('engagement')->getField('count') : '?';
            $out['talking'] = !empty($graphNode->getField('talking_about_count')) ? $graphNode->getField('talking_about_count') : '?';

            if ($out['likes'] == '?') {
                echo "not found";
            } else echo "ok";
            echo "\n";

            $out['postCount']  = $this->getPostCount($fbPageId, $fb, $req['pStats']);
            $out['videoCount'] = $this->getVideoCount($fbPageId, $fb, $req['vStats']);
        }

        if ($what == 'info') {
            $out['photoCount'] = $this->getImageCount($fbPageId, $fb, $req['iStats']);
            $out['eventCount'] = $this->getEventCount($fbPageId, $fb, $req['eStats']);
        }

        if ($what == null || $what == 'posts') {
            $temp = $this->getPostDetails($fbPageId, $fb, $req['pDetails'], $code, $full);
            $out['posts']  = isset($temp['posts'])  ? $temp['posts']  : null;
            $out['videos'] = isset($temp['videos']) ? $temp['videos'] : null;
        }

        if ($what == null || $what == 'images') {
            $temp = $this->getImageDetails($fbPageId, $fb, $req['iDetails'], $code, $full);
            $out['photoCount'] = isset($temp['photoCount']) ? $temp['photoCount'] : null;
            $out['photos']     = isset($temp['photos'])     ? $temp['photos']     : null;
        }

        if ($what == null || $what == 'events') {
            $temp = $this->getEventDetails($fbPageId, $fb, $req['eDetails'], $code, $full);
            $out['eventCount'] = isset($temp['eventCount']) ? $temp['eventCount'] : null;
            $out['events']     = isset($temp['events'])     ? $temp['events']     : null;
        }

        return $out;
    }


    /**
     * Finds best resolution of an image
     * @param  string $fbPageId
     * @param  string $fb
     * @param  string $imgId
     * @param  bool   $cover
     * @return string
     */
    public function getImageSource($fbPageId, $fb, $imgId, $cover = false) {
        $request = $fb->request('GET', $imgId, ['fields' => 'height,width,album,images']);
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

        if (!$cover) {
            foreach ($images as $key => $img) {
                if ($img->getField('width') < 481 && $img->getField('height') < 481) {
                    return $img->getField('source'); // get biggest available up to 480x480
                }
            }
            return $img->getField('source'); // if above fails, just get whatever's available

        } else {
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

    }


//
// Counting stats
//

    /**
     * Post count
     * @param  string $fbPageId
     * @param  string $fb
     * @param  string $req
     * @return int
     */
    public function getPostCount($fbPageId, $fb, $req) {
        $request = $fb->request('GET', $fbPageId, ['fields' => $req]);
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


    /**
     * Image count
     * @param  string $fbPageId
     * @param  string $fb
     * @param  string $req
     * @return int
     */
    public function getImageCount($fbPageId, $fb, $req) {
        $request = $fb->request('GET', $fbPageId, ['fields' => $req]);
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
                $photoCount[] = $album->getField('photo_count');
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


    /**
     * Video count
     * @param  string $fbPageId
     * @param  string $fb
     * @param  string $req
     * @return int
     */
    public function getVideoCount($fbPageId, $fb, $req) {
        $request = $fb->request('GET', $fbPageId, ['fields' => $req]);
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


    /**
     * Event count
     * @param  string $fbPageId
     * @param  string $fb
     * @param  string $req
     * @return int
     */
    public function getEventCount($fbPageId, $fb, $req) {
        $request = $fb->request('GET', $fbPageId, ['fields' => $req]);
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
// Getting details
//

    /**
     * Gets post details (inc. videos)
     * @param  string $fbPageId
     * @param  string $fb
     * @param  string $req
     * @param  string $code
     * @param  bool   $full
     * @return array
     */
    public function getPostDetails($fbPageId, $fb, $req, $code, $full) {
        $scraper = $this->container->get('ScraperServices');

        $request = $fb->request('GET', $fbPageId, ['fields' => $req]);
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

            $timeLimit = $scraper->getTimeLimit('fb', 'T', $code, $full);
            echo "page ";
            $pageCount = 0;

            do {
                echo $pageCount .', ';

                foreach ($fdPosts as $key => $post) {

                    $type = $post->getField('type'); // types = 'status', 'link', 'photo', 'video', 'event'
                    if ($type != 'photo' && $type != 'event') { // get photos and events separately to get all details

                        // $img = null;
                        if ($type == 'video') {
                            $temp = $post->getField('link');
                            if (strpos($temp, 'youtu')) { // youtube.com or youtu.be

                                $temp = urldecode($temp);
                                switch (true) {
                                    case strpos($temp, 'v='):
                                        $idPos = strpos($temp, 'v=')+2;
                                        break;
                                    case strpos($temp, 'youtu.be/'):
                                        $idPos = strpos($temp, '.be/')+4;
                                        break;
                                    }

                                $vidId  = substr($temp, $idPos, 11);
                                $imgSrc = "https://img.youtube.com/vi/".$vidId."/mqdefault.jpg"; // 320x180 (only 16:9 option)
                                // default=120x90, mqdefault=320x180, hqdefault=480x360, sddefault=640x480 (all 4:3 w/ letterbox)
                                $imgBkp = $post->getField('picture'); // 130x130 thumbnail
                            } else {
                                $imgSrc = $post->getField('picture');
                                $imgBkp = null;
                            }
                        } else {
                            $type   = 'post';
                            $imgSrc = $post->getField('picture') ? $post->getField('picture'): null;
                            $imgBkp = null;
                        }

                        if ($imgSrc && strpos($imgSrc, 'external.xx.fbcdn.net')) {
                            $stPos  = strpos($imgSrc, '&url=')+5;
                            $edPos  = strpos($imgSrc, '&cfs=');
                            $length = $edPos - $stPos;
                            $temp   = substr($imgSrc, $stPos, $length);
                            $imgSrc = urldecode($temp);
                        }

                        $img  = $imgSrc ? $scraper->saveImage('fb', $code, $imgSrc, $post->getField('id'), $imgBkp) :  null;
                        $text = !empty($post->getField('message')) ? $post->getField('message') : $post->getField('story');

                        $likeCount     = $this->getStatCount($post->getField('likes'));
                        $reactionCount = $this->getStatCount($post->getField('reactions'));
                        $commentCount  = $this->getStatCount($post->getField('comments'));
                        $shareCount    = !empty($post->getField('shares')) ? json_decode($post->getField('shares')->getField('count'), true) : null;

                        $out[$type.'s'][] = [
                            'postId'    => $post->getField('id'),
                            'postTime'  => $post->getField('updated_time'), // DateTime
                            'postText'  => $text,
                            'postImage' => $img,
                            'postLikes' => $reactionCount,
                            'postData'  => [
                                'id'         => $post->getField('id'),
                                'posted'     => $post->getField('created_time')->format('Y-m-d H:i:s'), // string
                                'updated'    => $post->getField('updated_time')->format('Y-m-d H:i:s'), // string
                                'text'       => $text,
                                'image'      => $img,
                                'img_source' => $imgSrc,
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
                                ],
                            ];
                    }
                }

                $timeCheck = $post->getField('created_time')->getTimestamp(); // check time of last scraped post
                $pageCount++;

            } while ($timeCheck > $timeLimit && $fdPosts = $fb->next($fdPosts));
            // while next page is not null and within our time limit

            $txtCount = array_key_exists('posts',  $out) ? count($out['posts'])  : 0;
            $vidCount = array_key_exists('videos', $out) ? count($out['videos']) : 0;
            echo "...".$txtCount." text posts and ".$vidCount." videos since ".date('d/m/Y', $timeCheck)." processed";

        } else {
            echo "not found";
        }
        echo "\n";
        return (isset($out)) ? $out : null;
    }


    /**
     * Gets image details
     * @param  string $fbPageId
     * @param  string $fb
     * @param  string $req
     * @param  string $code
     * @param  bool   $full
     * @return array
     */
    public function getImageDetails($fbPageId, $fb, $req, $code, $full) {
        $scraper = $this->container->get('ScraperServices');

        $request = $fb->request('GET', $fbPageId, ['fields' => $req]);
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
        echo "     + Photo details... ";

        if (!empty($fdAlbums)) {
            $timeLimit = $scraper->getTimeLimit('fb', 'I', $code, $full);
            echo "page ";
            $pageCount = 0;
            foreach ($fdAlbums as $key => $album) {
                $photoCount[] = $album->getField('photo_count');
                $fdPhotos     = $album->getField('photos');
                if (!empty($fdPhotos)) {
                    do {
                        echo $pageCount .', ';
                        foreach ($fdPhotos as $key => $photo) {

                            $imgSrc = $this->getImageSource($fbPageId, $fb, $photo->getField('id')); // ~480x480 (or closest)
                            $imgBkp = $photo->getField('picture'); // 130x130 thumbnail
                            $img    = $scraper->saveImage('fb', $code, $imgSrc, $photo->getField('id'), $imgBkp);

                            $likeCount     = $this->getStatCount($photo->getField('likes'));
                            $reactionCount = $this->getStatCount($photo->getField('reactions'));
                            $commentCount  = $this->getStatCount($photo->getField('comments'));
                            $shareCount    = count(json_decode($photo->getField('sharedposts'), true));
                            $out['photos'][] = [
                                'postId'    => $photo->getField('id'),
                                'postTime'  => $photo->getField('updated_time'), // DateTime
                                'postText'  => $photo->getField('name'),
                                'postImage' => $img,
                                'postLikes' => $reactionCount,
                                'postData'  => [
                                    'id'         => $photo->getField('id'),
                                    'posted'     => $photo->getField('created_time')->format('Y-m-d H:i:s'), // string
                                    'updated'    => $photo->getField('updated_time')->format('Y-m-d H:i:s'), // string
                                    'text'       => $photo->getField('name'),
                                    'image'      => $img,
                                    'img_source' => $imgSrc,
                                    'url'        => $photo->getField('link'),
                                    'album'      => [
                                        'name'       => $album->getField('name'),
                                        'id'         => $album->getField('id'),
                                        ],
                                    'likes'      => $likeCount,
                                    'reactions'  => $reactionCount,
                                    'comments'   => $commentCount,
                                    'shares'     => $shareCount
                                    ]
                                ];
                        }
                        $timeCheck = $photo->getField('updated_time')->getTimestamp(); // check time of last scraped post
                        $pageCount++;
                    } while ($timeCheck > $timeLimit && $fdPhotos = $fb->next($fdPhotos));
                    // while next page is not null and within our time limit
                }
            }
            $out['photoCount'] = array_sum($photoCount);

            echo "...".$out['photoCount']." found, ".count($out['photos'])." since ".date('d/m/Y', $timeCheck)." processed";
        } else {
            $out['photoCount'] = 0;
            echo "not found";
        }
        echo "\n";
        return $out;
    }


    /**
     * Gets event details
     * @param  string $fbPageId
     * @param  string $fb
     * @param  string $req
     * @param  string $code
     * @param  bool   $full
     * @return array
     */
    public function getEventDetails($fbPageId, $fb, $req, $code, $full) {
        $scraper = $this->container->get('ScraperServices');

        $request = $fb->request('GET', $fbPageId, ['fields' => $req]);
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

            $timeLimit = $scraper->getTimeLimit('fb', 'E', $code, $full);
            echo "page ";
            $pageCount = 0;

            do { // process current page of results
                echo $pageCount .', ';
                if (!empty($fdEvents)) {
                    foreach ($fdEvents as $key => $event) {

                        $place = $event->getField('place');
                        if (!empty($place)) { // must be checked in advance, else will break if null
                            $placeName = $place->getField('name');
                            $location  = $place->getField('location');
                        } else $placeName = null;

                        if (!empty($location)) { // must be checked in advance, else will break if null
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
                                'id'          => $event->getField('id'),
                                'start_time'  => $event->getField('start_time')->format('Y-m-d H:i:s'), // string
                                'updated'     => $event->getField('updated_time')->format('Y-m-d H:i:s'), // string
                                'text'        => $event->getField('name'),
                                'description' => $event->getField('description'),
                                'image'       => $img,
                                'img_source'  => $imgSrc,
                                'place'       => $placeName,
                                'address'     => $placeAddress,
                                'url'         => 'https://www.facebook.com/events/'.$event->getField('id'),
                                'attending'   => $event->getField('attending_count'),
                                'interested'  => $event->getField('interested_count'),
                                'comments'    => $commentCount
                                ]
                            ];
                    }

                    $timeCheck = $event->getField('updated_time')->getTimestamp(); // check time of last scraped post
                    $pageCount++;
                }

            } while ($timeCheck > $timeLimit && $fdEvents = $fb->next($fdEvents));
            // while next page is not null and within our time limit

            $out['eventCount'] = count($out['events']);
            echo "...".$out['eventCount']." found and processed";

        } else {
            echo "not found";
            $out['eventCount'] = 0;
        }

        echo "\n";
        return $out;
    }

}