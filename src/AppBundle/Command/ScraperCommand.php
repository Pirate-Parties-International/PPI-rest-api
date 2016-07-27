<?php
namespace AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use Pirates\PapiInfo\Compile;

use TwitterAPIExchange;
use Madcoda\Youtube;

use AppBundle\Entity\Party;
use AppBundle\Entity\Metadata;
use AppBundle\Entity\Statistic;

use Facebook\Facebook;
use Facebook\FacebookSDKException;
use Facebook\FacebookResponseException;


class ScraperCommand extends ContainerAwareCommand
{
    
    protected $stats = [];
    protected $meta = [];

    public $coverRoot = "";

    protected function configure()
    {
        $this
            ->setName('papi:scraper')
            ->setDescription('Scrapes FB, TW and G+ data. Should be run once per day.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        
        $this->container = $this->getContainer();
        $this->em = $this->container->get('doctrine')->getManager();

        $appRoot = $this->container->get('kernel')->getRootDir() . '/..';
        $this->coverRoot = $appRoot . '/web/img/fb-covers/';
        
        $this->output = $output;
        $this->logger = $this->getContainer()->get('logger');
        $logger = $this->logger;

        $output->writeln("##### Starting scraper #####");

        $output->writeln("# Getting all parties.");
        $parties = $this->getAllParties();
        $output->writeln("Done");

        foreach ($parties as $code => $party) {
            
            $output->writeln(" - Processing " . $code);
            $sn = $party->getSocialNetworks();

            if (empty($sn)) {
                continue;
            }

            //
            // FACEBOOK
            // 
            if (!empty($sn['facebook']) && !empty($sn['facebook']['username'])) {
                $output->writeln("     + Starting Facebook import");
                $fd = $this->getFBData($sn['facebook']['username']); 

                if ($fd == false || empty($fd['fan_count'])) {
                    $output->writeln("     + ERROR while retrieving FB data");
                } else {
                    $output->writeln("     + Facebook data retrived");
                    $this->addStatistic(
                        $code, 
                        Statistic::TYPE_FACEBOOK, 
                        Statistic::SUBTYPE_LIKES, 
                        $fd['fan_count']
                    );
                    $output->writeln("     + Statistic added");

                    $cover = $this->getFacebookCover($code, $fd['cover']);
                    $output->writeln("     + Cover retrived");

                    $this->addMeta(
                        $code,
                        Metadata::TYPE_FACEBOOK_COVER,
                        $cover
                    );
                    $output->writeln("     + Meta added");
                }

            }

            //
            // TWITTER
            // 
            if (!empty($sn['twitter']) && !empty($sn['twitter']['username'])) {
                $output->writeln("     + Starting Twitter import");
                $td = $this->getTwitterData($sn['twitter']['username']);

                if ($fd == false ||
                    empty($td['followers']) ||
                    empty($td['tweets'])
                    ) {
                    $output->writeln("     + ERROR while retrieving TW data");
                } else {
                    $output->writeln("     + Twitter data retrived");
                    $this->addStatistic(
                        $code, 
                        Statistic::TYPE_TWITTER, 
                        Statistic::SUBTYPE_FOLLOWERS, 
                        $td['followers']
                    );
                    $this->addStatistic(
                        $code, 
                        Statistic::TYPE_TWITTER, 
                        Statistic::SUBTYPE_TWEETS, 
                        $td['tweets']
                    );
                    $output->writeln("     + Statistic added");
                }
            }

            //
            // Google+
            // 
            if (!empty($sn['googlePlus'])) {
                $output->writeln("     + Starting GooglePlus import");
                $gd = $this->getGooglePlusData($sn['googlePlus']);

                if ($gd == false ||
                    empty($gd)
                    ) {
                    $output->writeln("     + ERROR while retrieving G+ data");
                } else {
                    $output->writeln("     + Twitter data retrived");
                    $this->addStatistic(
                        $code, 
                        Statistic::TYPE_GOOGLEPLUS, 
                        Statistic::SUBTYPE_FOLLOWERS, 
                        $gd
                    );
                    $output->writeln("     + Statistic added");
                }
            }

            //
            // Youtube
            // 
            if (!empty($sn['youtube'])) {
                $output->writeln("     + Starting Youtube import");
                $yd = $this->getYoutubeData($sn['youtube']);

                if ($yd == false ||
                    empty($yd)
                    ) {
                    $output->writeln("     + ERROR while retrieving G+ data");
                } else {
                    $output->writeln("     + Youtube data retrived");
                    $this->addStatistic(
                        $code, 
                        Statistic::TYPE_YOUTUBE, 
                        Statistic::SUBTYPE_SUBSCRIBERS, 
                        $yd['stats']['subscriberCount']
                    );

                    $this->addStatistic(
                        $code, 
                        Statistic::TYPE_YOUTUBE, 
                        Statistic::SUBTYPE_VIEWS,
                        $yd['stats']['viewCount']
                    );

                    $this->addStatistic(
                        $code, 
                        Statistic::TYPE_YOUTUBE, 
                        Statistic::SUBTYPE_VIDEOS, 
                        $yd['stats']['videoCount']
                    );

                    $output->writeln("     + Statistic added");

                    if (!empty($yd['videos'])) {
                        $this->addMeta(
                            $code,
                            Metadata::TYPE_YOUTUBE_VIDEOS,
                            json_encode($yd['videos'])
                        );
                    }

                    $output->writeln("     + Metadata added");
                    
                }
            }


            
        }

        $output->writeln("# Saving to DB");
        $this->em->flush();

        $output->writeln("# Done");        
        
    }

    /**
     * Builds a Statistic object
     * @param string $type    
     * @param string $subType 
     * @param integer $value   
     *
     * @return Statistic
     */
    public function addStatistic($code, $type, $subType, $value) {
        $s = new Statistic();
        $s->setCode($code);
        $s->setType($type);
        $s->setSubType($subType);
        $s->setValue($value);
        $s->setTimestamp(new \DateTime());

        $this->em->persist($s);
        
        $this->stats[] = $s;
        return $s;
    }

    /**
     * Builds or updates a Metadata object
     * 
     * @param string $code 
     * @param string $type 
     * @param string $value
     *
     * @return Metadata
     */
    public function addMeta($code, $type, $value) {
        $m = $this->container->get('doctrine')
        ->getRepository('AppBundle:Metadata')
        ->findOneBy([
            'type' => $type,
            'code' => $code
            ]);

        if (!$m) {
            $m = new Metadata();
            $m->setCode($code);
            $m->setType($type);      
        }

        $m->setValue($value);

        $this->em->persist($m);

        $this->meta[] = $m;
        return $m;
    }

    /**
     * Retrives facebook covers and saves them to disk
     * @param  string $code 
     * @param  string $url  
     * @return string       local relative path
     */
    public function getFacebookCover($code, $url) {
        if (!is_dir($this->coverRoot)) {
            mkdir($this->coverRoot, 0755, true);
        }

        preg_match('/.+\.(png|jpg)/i', $url, $matches);
        $fileEnding = $matches[1];

        $img = file_get_contents($url);
        $filename = strtolower($code) . '.' . $fileEnding;
        $fullPath = $this->coverRoot . $filename;
        file_put_contents($fullPath, $img);

        $this->cropImage($fullPath);

        return '/img/fb-covers/' . $filename; 
    }

    /**
     * Crops the image
     * @param  string $path 
     * @return null
     */
    public function cropImage($path) {
        $crop_width = 851;
        $crop_height = 315;

        $image = new \Imagick($path);
        $geometry = $image->getImageGeometry();

        $width = $geometry['width'];
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
     * Queries DB for all parties
     * @return array
     */
    public function getAllParties() {
        
        $parties = $this->container->get('doctrine')
        ->getRepository('AppBundle:Party')
        ->findAll();
        
        $allData = array();
        foreach ($parties as $party) {
            $allData[strtolower($party->getCode())] = $party;
        }

        return $allData;
    }

    /**
     * Queries FB for likes and cover picture
     * @return array
     */
    public function getFBData($fbPageId) {
        $fb = new Facebook([
          'app_id' => $this->container->getParameter('fb_app_id'),
          'app_secret' => $this->container->getParameter('fb_app_secret'),
          'default_graph_version' => 'v2.7',
        ]);
        $fb->setDefaultAccessToken($this->container->getParameter('fb_access_token'));

        $request = $fb->request(
          'GET',
          $fbPageId,
          array(
            'fields' => 'about,general_info,description,founded,cover,engagement,fan_count,talking_about_count,emails,location,single_line_address'
          )
        );

        try {
          $response = $fb->getClient()->sendRequest($request);
        } catch(Facebook\Exceptions\FacebookResponseException $e) {
          // When Graph returns an error
          echo 'Graph returned an error: ' . $e->getMessage();
          exit;
        } catch(Facebook\Exceptions\FacebookSDKException $e) {
          // When validation fails or other local issues
          echo 'Facebook SDK returned an error: ' . $e->getMessage();
          exit;
        } catch (\Exception $e) {
            echo $fbPageId . " - Exception: " . $e->getMessage();
            return false;
        }

        $graphNode = $response->getGraphNode();
        var_dump($graphNode); die;
        $out['likes'] = $graphNode->getField('fan_count');

        //
        // Second step for images
        // 
        $imageId =  $graphNode->getField('cover')->getField('cover_id');

        $request = $fb->request(
          'GET',
          $imageId,
          array(
            'fields' => 'height,width,album,images'
          )
        );


        try {
          $response = $fb->getClient()->sendRequest($request);
        } catch(Facebook\Exceptions\FacebookResponseException $e) {
          // When Graph returns an error
          echo 'Graph returned an error: ' . $e->getMessage();
          exit;
        } catch(Facebook\Exceptions\FacebookSDKException $e) {
          // When validation fails or other local issues
          echo 'Facebook SDK returned an error: ' . $e->getMessage();
          exit;
        } catch (\Exception $e) {
            echo $fbPageId . " - Exception: " . $e->getMessage();
            return false;
        }

        $graphNode = $response->getGraphNode();

        $images = $graphNode->getField('images');

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
            $t = max(array_keys($tmpI));
            $img = $tmpI[$t];
        } else {
            $t = max(array_keys($tmpA));
            $img = $tmpA[$t];
        }

        $out['cover'] = $img;



        return $out;
    }

    public function getTwitterData($username) {
        $settings = array(
            'oauth_access_token' => $this->container->getParameter('tw_oauth_access_token'),
            'oauth_access_token_secret' => $this->container->getParameter('tw_oauth_access_token_secret'),
            'consumer_key' => $this->container->getParameter('tw_consumer_key'),
            'consumer_secret' => $this->container->getParameter('tw_consumer_secret')
        );

        $url = 'https://api.twitter.com/1.1/users/show.json';
        $getfield = '?screen_name='.str_replace("@", "", $username);
        $requestMethod = 'GET';

        $twitter = new TwitterAPIExchange($settings);
        $data = $twitter->setGetfield($getfield)
            ->buildOauth($url, $requestMethod)
            ->performRequest();

        if (empty($data)) {
            return false;
        }

        $data = json_decode($data);

        if (empty($data->followers_count)) {
            return false;
        }

        $out['followers'] = $data->followers_count;
        $out['tweets'] = $data->statuses_count;

        return $out;
    }

    public function getGooglePlusData($id) {
        $apikey = $this->container->getParameter('gplus_api_key');
        $google = $this->curl(
            sprintf('https://www.googleapis.com/plus/v1/people/%s?key=%s',
                $id, $apikey)
            );
        $data = json_decode( $google );
        if (empty($data) || !isset($data->circledByCount)) {
            return false;
        }
        return $data->circledByCount;

    }

    public function getYoutubeData($id) {
        $apikey = $this->container->getParameter('gplus_api_key');
        $youtube = new Youtube(array('key' => $apikey));

        $data = $youtube->getChannelByName($id);

        if (empty($data)) {
            return false;
        }
        if (empty($data->statistics) || empty($data->statistics->viewCount)) {
            return false;
        }
        $out['stats']['viewCount'] = $data->statistics->viewCount;
        $out['stats']['subscriberCount'] = $data->statistics->subscriberCount;
        $out['stats']['videoCount'] = $data->statistics->videoCount;

        $playlist = $data->contentDetails->relatedPlaylists->uploads;

        $videos = $youtube->getPlaylistItemsByPlaylistId($playlist);

        if (!empty($videos)) {
            $out['videos'] = [];

            foreach ($videos as $key => $vid) {
//                if ($key > 4) break;

                $vidId = $vid->snippet->resourceId->videoId;
                $vidInfo = $youtube->getVideoInfo($vidId);
                $out['videos'][] = [
                    'title' => $vid->snippet->title,
                    'tumb' => $vid->snippet->thumbnails->medium->url,
                    'date' => $vid->snippet->publishedAt,
                    'id' => $vidId,
                    'views' => $vidInfo->statistics->viewCount
//                    'likes' => $vidInfo->statistics->likeCount,
//                    'favs' => $vidInfo->statistics->favoriteCount,
//                    'comments' => $vidInfo->statistics->commentCount
                ];
            }
        }



        return $out;

    }

    public function curl($url) {
        // Get cURL resource
        $curl = curl_init();
        // Set some options - we are passing in a useragent too here
        curl_setopt_array($curl, array(
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_URL => $url,
            CURLOPT_USERAGENT => 'PAPI'
        ));
        // Send the request & save response to $resp
        $resp = curl_exec($curl);
        // Close request to clear up some resources
        curl_close($curl);

        return $resp;
    }
}