<?php

namespace AppBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

use AppBundle\Entity\Statistic as Stat;

class SocialController extends BaseController
{
    /**
    * Queries for a single type of social media post
    * @param  string $code
    * @param  string $type
    * @param  string $subType
    * @return SocialMedia
    */
    public function getOneSocial($code, $type, $subType) {
        $socialMedia = $this->getDoctrine()
            ->getRepository('AppBundle:SocialMedia')
            ->findBy(['code' => $code, 'type' => $type, 'subType' => $subType]);

        if (!$socialMedia) {
            return false;
        }

        return $socialMedia;
    }


    /**
    * Queries for all social media posts
    * @param  string $code    <optional>
    * @param  string $type    <optional>
    * @param  string $subType <optional>
    * @param  string $fields  <optional>
    * @param  string $orderBy <optional>
    * @param  int    $limit   <optional>
    * @param  int    $offset  <optional>
    * @param  int    $recent  <optional>
    * @return array
    */
    public function getAllSocial($code = null, $type = null, $subType = null, $fields = null, $orderBy = null, $direction = null, $limit = 100, $offset = 0, $recent = null) {
        $terms   = [];
        $orderBy = is_null($orderBy) ? 'postTime' : $orderBy;

        if (!$direction) {
            $direction = ($orderBy == 'code') ? 'ASC' : 'DESC';
        }

        $terms = [
            'code'      => $code,
            'type'      => $type,
            'subType'   => $subType,
            'orderBy'   => $orderBy,
            'direction' => $direction,
            'limit'     => $limit,
            'offset'    => $offset,
            'recent'    => $recent
        ];

        $socialMedia = $this->getSocialDql($terms);

        if ($fields) {
            $socialMedia = $this->getSelectSocial($socialMedia, $fields);
        }

        return $socialMedia;
    }


    /**
     * Return social media array via dql
     * @param  array $terms
     * @return array
     */
    public function getSocialDql($terms) {
        $query = $this->getDoctrine()->getManager()
            ->createQueryBuilder()
            ->select('p')
            ->from('AppBundle:SocialMedia', 'p');

        if ($terms['code']) {
            $query->where(sprintf("p.code = '%s'", $terms['code']));
        }

        if ($terms['type']) {
            $query->andwhere(sprintf("p.type = '%s'", $terms['type']));
        }

        if ($terms['subType']) {
            $query->andwhere(sprintf("p.subType = '%s'", $terms['subType']));
        }

        if ($terms['recent']) {
            $recentString = date('Y-m-d H:i:s', $terms['recent']);
            $query->andWhere(sprintf("p.postTime > '%s'", $recentString));
        }

        $query->setFirstResult($terms['offset'])
            ->setMaxresults($terms['limit'])
            ->orderBy(sprintf("p.%s", $terms['orderBy']), $terms['direction']);

        $socialMedia = $query->getQuery()->getResult();

        return $socialMedia;
    }


    /**
    * Builds an array of select fields to return to the API
    * @param  object $socialMedia
    * @param  string $fields
    * @return array
    */
    public function getSelectSocial($socialMedia, $fields) {
        $fields = str_replace(' ', '', $fields);
        $terms  = explode(',', $fields);
        $out    = [];

        foreach ($socialMedia as $social) {
            $data = $social->getPostData();

            if (in_array('no_retweets', $terms) && !empty($data['reply_to'])) {
                continue;
            }

            $temp = [
                'code'     => $social->getCode(),
                'type'     => $social->getType(),
                'sub_type' => $social->getSubType(),
                'post_id'  => $data['id'],
                ];

            foreach ($terms as $field) {
                if ($field == 'time' || $field == 'date') {
                    if ($temp['sub_type'] == 'E') {
                        $temp['post_' . $field] = isset($data['start_time']) ? $data['start_time'] : null;
                    } else $temp['post_' . $field] = isset($data['posted']) ? $data['posted'] : null;
                    continue;
                }

                if ($field == 'shares' && $temp['type'] == 'tw') {
                    $temp['post_shares'] = isset($data['retweets']) ? $data['retweets'] : null;
                    continue;
                }

                switch ($field) {
                    case 'total_engagement':
                        $temp['post_total_engagement'] = $this->getPostEngagement($social);
                        break;
                    case 'audience_reach':
                        $temp['post_audience_reach']   = $this->getPostReach($social);
                        break;
                    case 'reach_per_capita':
                        $temp['post_reach_per_capita'] = $this->getPostReachPerCapita($social);
                        break;
                    default:
                        $temp['post_' . $field] = isset($data[$field]) ? $data[$field] : null;
                }
            }

            $out[] = $temp;
        }

        return $out;
    }


    /**
     * Returns the total engagement score of a post
     * @param  object $item
     * @return int
     */
    public function getPostEngagement($item) {
        $data = $item->getPostData();

        $comments  = isset($data['comments'])  ? $data['comments']  : 0;
        $likes     = isset($data['likes'])     ? $data['likes']     : 0;
        $reactions = isset($data['reactions']) ? $data['reactions'] : 0;
        $retweets  = isset($data['retweets'])  ? $data['retweets']  : 0;
        $shares    = isset($data['shares'])    ? $data['shares']    : 0;
        $views     = isset($data['views'])     ? $data['views']     : 0;

        switch ($item->getType()) {
            case 'fb':
                return $reactions + $shares + $comments;
            case 'tw':
                return $likes + $retweets + $comments;
            case 'yt':
                return $views + $likes + $comments;
            default:
                return $likes + $shares + $comments;
        }
    }


    /**
     * Returns the percentage of a post's engagement per total audience
     * (i.e. followers, subscribers, etc.)
     * @param  object $item
     * @return float
     */
    public function getPostReach($item) {
        switch ($item->getType()) {
            case 'fb':
                $statType = Stat::TYPE_FACEBOOK;
                $subType  = Stat::SUBTYPE_LIKES;
                break;
            case 'tw':
                $statType = Stat::TYPE_TWITTER;
                $subType  = Stat::SUBTYPE_FOLLOWERS;
                break;
            case 'yt':
                $statType = Stat::TYPE_YOUTUBE;
                $subType  = Stat::SUBTYPE_SUBSCRIBERS;
                break;
            case 'g+':
                $statType = Stat::TYPE_GOOGLEPLUS;
                $subType  = Stat::SUBTYPE_FOLLOWERS;
                break;
            }

        $engagement   = $this->getPostEngagement($item);
        $totalReach   = $this->getStat($item->getCode(), $statType, $subType);
        $reachPercent = $totalReach / 100;

        return $engagement / $reachPercent;
    }


    /**
     * Returns the percentage of a post's engagement per capita
     * @param  object $item
     * @return int
     */
    public function getPostReachPerCapita($item) {
        $partyCode = strtoupper($item->getCode());

        $population = $this->container
            ->get('PopulationService')
            ->getPopulation($partyCode);

        if (is_null($population)) {
            return null;
        }

        $engagement = $this->getPostEngagement($item);
        $popPercent = $population / 100;

        return $engagement / $popPercent;
    }

}
