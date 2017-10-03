<?php

namespace AppBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

use AppBundle\Entity\Metadata as Meta;
use AppBundle\Entity\Statistic as Stat;
use AppBundle\Entity\SocialMedia as Sm;

class DataController extends BaseController
{
    public function getCoverImage($code) {
        $meta = $this->getMeta($code, Meta::TYPE_FACEBOOK_COVER);

        if(!$meta) {
            return '/img/generic_cover.jpg';
        }

        return $meta;
    }


    public function getFacebookLikes($code) {
        return $this->getStat($code, Stat::TYPE_FACEBOOK, Stat::SUBTYPE_LIKES);
    }

    public function getFacebookStats($code) {
        $out = [
            'likes'         => $this->getStat($code, Stat::TYPE_FACEBOOK, Stat::SUBTYPE_LIKES),
            'talking_about' => $this->getStat($code, Stat::TYPE_FACEBOOK, Stat::SUBTYPE_TALKING),
            'posts'         => $this->getStat($code, Stat::TYPE_FACEBOOK, Stat::SUBTYPE_POSTS),
            'images'        => $this->getStat($code, Stat::TYPE_FACEBOOK, Stat::SUBTYPE_IMAGES),
            'videos'        => $this->getStat($code, Stat::TYPE_FACEBOOK, Stat::SUBTYPE_VIDEOS),
            'events'        => $this->getStat($code, Stat::TYPE_FACEBOOK, Stat::SUBTYPE_EVENTS),
        ];

        return $out;
    }

    public function getFacebookData($code) {
        $out = [
            'likes'        => $this->getStat($code, Stat::TYPE_FACEBOOK, Stat::SUBTYPE_LIKES),
            'talkingAbout' => $this->getStat($code, Stat::TYPE_FACEBOOK, Stat::SUBTYPE_TALKING),
            'postCount'    => $this->getStat($code, Stat::TYPE_FACEBOOK, Stat::SUBTYPE_POSTS),
            'photoCount'   => $this->getStat($code, Stat::TYPE_FACEBOOK, Stat::SUBTYPE_IMAGES),
            'videoCount'   => $this->getStat($code, Stat::TYPE_FACEBOOK, Stat::SUBTYPE_VIDEOS),
            'eventCount'   => $this->getStat($code, Stat::TYPE_FACEBOOK, Stat::SUBTYPE_EVENTS),
        ];
        $out['pageInfo'] = $this->getMeta($code, Meta::TYPE_FACEBOOK_INFO);

        $posts  = $this->getOneSocial($code, Sm::TYPE_FACEBOOK, Sm::SUBTYPE_TEXT);
        if (!empty($posts)) {
            $out['posts'] = $posts;
        }
        $photos = $this->getOneSocial($code, Sm::TYPE_FACEBOOK, Sm::SUBTYPE_IMAGE);
        if (!empty($photos)) {
            $out['images'] = $photos;
        }
        $videos = $this->getOneSocial($code, Sm::TYPE_FACEBOOK, Sm::SUBTYPE_VIDEO);
        if (!empty($videos)) {
            $out['videos'] = $videos;
        }
        $events = $this->getOneSocial($code, Sm::TYPE_FACEBOOK, Sm::SUBTYPE_EVENT);
        if (!empty($events)) {
            $out['events'] = $events;
        }

        return $out;
    }


    public function getTwitterFollowers($code) {
        return $this->getStat($code, Stat::TYPE_TWITTER, Stat::SUBTYPE_FOLLOWERS);
    }

    public function getTwitterStats($code) {
        $out = [
            'likes'     => $this->getStat($code, Stat::TYPE_TWITTER, Stat::SUBTYPE_LIKES),
            'followers' => $this->getStat($code, Stat::TYPE_TWITTER, Stat::SUBTYPE_FOLLOWERS),
            'following' => $this->getStat($code, Stat::TYPE_TWITTER, Stat::SUBTYPE_FOLLOWING),
            'tweets'    => $this->getStat($code, Stat::TYPE_TWITTER, Stat::SUBTYPE_POSTS),
            'images'    => $this->getStat($code, Stat::TYPE_TWITTER, Stat::SUBTYPE_IMAGES),
            'videos'    => $this->getStat($code, Stat::TYPE_TWITTER, Stat::SUBTYPE_VIDEOS),
        ];

        return $out;
    }

    public function getTwitterData($code) {
        $out = [
            'likes'      => $this->getStat($code, Stat::TYPE_TWITTER, Stat::SUBTYPE_LIKES),
            'followers'  => $this->getStat($code, Stat::TYPE_TWITTER, Stat::SUBTYPE_FOLLOWERS),
            'following'  => $this->getStat($code, Stat::TYPE_TWITTER, Stat::SUBTYPE_FOLLOWING),
            'tweetCount' => $this->getStat($code, Stat::TYPE_TWITTER, Stat::SUBTYPE_POSTS),
            'imageCount' => $this->getStat($code, Stat::TYPE_TWITTER, Stat::SUBTYPE_IMAGES),
            'videoCount' => $this->getStat($code, Stat::TYPE_TWITTER, Stat::SUBTYPE_VIDEOS),
        ];
        $out['pageInfo']['about'] = $this->getMeta($code, Meta::TYPE_TWITTER_INFO);

        $tweets = $this->getOneSocial($code, Sm::TYPE_TWITTER, Sm::SUBTYPE_TEXT);
        if (!empty($tweets)) {
            $out['tweets'] = $tweets;
        }
        $images = $this->getOneSocial($code, Sm::TYPE_TWITTER, Sm::SUBTYPE_IMAGE);
        if (!empty($images)) {
            $out['images'] = $images;
        }
        $videos = $this->getOneSocial($code, Sm::TYPE_TWITTER, Sm::SUBTYPE_VIDEO);
        if (!empty($videos)) {
            $out['videos'] = $videos;
        }

        return $out;
    }


    public function getGooglePlusFollowers($code) {
        return $this->getStat($code, Stat::TYPE_GOOGLEPLUS, Stat::SUBTYPE_FOLLOWERS);
    }

    public function getYoutubeStats($code) {
        $out = [
            'subscribers' => $this->getStat($code, Stat::TYPE_YOUTUBE, Stat::SUBTYPE_SUBSCRIBERS),
            'views'       => $this->getStat($code, Stat::TYPE_YOUTUBE, Stat::SUBTYPE_VIEWS),
            'videos'      => $this->getStat($code, Stat::TYPE_YOUTUBE, Stat::SUBTYPE_VIDEOS),
        ];

        return $out;
    }

    public function getYoutubeData($code) {
        $out = [
            'subscribers' => $this->getStat($code, Stat::TYPE_YOUTUBE, Stat::SUBTYPE_SUBSCRIBERS),
            'views'       => $this->getStat($code, Stat::TYPE_YOUTUBE, Stat::SUBTYPE_VIEWS),
            'videoCount'  => $this->getStat($code, Stat::TYPE_YOUTUBE, Stat::SUBTYPE_VIDEOS),
        ];

        $videos = $this->getOneSocial($code, Sm::TYPE_YOUTUBE, Sm::SUBTYPE_VIDEO);
        if (!empty($videos)) {
            $out['videos'] = $videos;
        }

        return $out;
    }
}
