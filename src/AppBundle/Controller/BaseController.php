<?php

namespace AppBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

class BaseController extends Controller
{
    /**
     * Queries for a single party
     * @param  string $code
     * @return Party
     */
    public function getOneParty($code) {
        $party = $this->getDoctrine()
            ->getRepository('AppBundle:Party')
            ->findOneByCode($code);

        return $party;
    }


    /**
     * Queries for all parties
     * @param  bool   $showDefunct      <optional>
     * @param  string $membershipFilter <optional>
     * @param  string $orderBy          <optional>
     * @return array
     */
    public function getAllParties($showDefunct = false, $membershipFilter = null, $orderBy = 'code') {
        $parties = $this->getDoctrine()
            ->getRepository('AppBundle:Party');

        $query = $parties->createQueryBuilder('qb')
            ->select('p')->from('AppBundle:Party', 'p');

        if (!is_null($membershipFilter)) { // filter by intOrg membership, 'ppi', 'ppeu' etc.
            $query->join('p.intMemberships', 'm')
                ->innerJoin('m.intOrg', 'o')
                ->where(sprintf("o.code = '%s'", $membershipFilter));
        }

        if (!is_null($showDefunct)) { // true = show only defunct, false = only non-defunct, null = show all
            $query->andwhere(sprintf("p.defunct = '%s'", $showDefunct));
        }

        switch ($orderBy) {
            case 'name':
                $query->orderBy('p.name', 'ASC');
                break;
            case 'country':
                $query->orderBy('p.countryName', 'ASC');
                break;
            default: // case 'code' or null
                $query->orderBy('p.code', 'ASC');
        }

        $parties = $query->getQuery()->getResult();

        $allData = array();
        foreach ($parties as $party) {
            $allData[strtolower($party->getCode())] = $party;
        }

        return $allData;
    }


    /**
     * Queries for a single statistic
     * @param  string $code    Party Code
     * @param  string $type
     * @param  string $subType <optional>
     * @return Statistic
     */
    public function getStat($code, $type, $subType) {
        $stat = $this->getDoctrine()
            ->getRepository('AppBundle:Statistic')
            ->findOneBy([
                'code'    => strtolower($code),
                'type'    => $type,
                'subType' => $subType
            ],
            [
                'timestamp' => 'DESC'
            ]
        );

        if (!$stat) {
            return '?';
        }

        return $stat->getValue();
    }


    /**
     * Queries for a single meta value
     * @param  string $code
     * @param  string $type
     * @return Metadata
     */
    public function getMeta($code, $type) {
        $meta = $this->getDoctrine()
            ->getRepository('AppBundle:Metadata')
            ->findOneBy([
                'code' => strtolower($code),
                'type' => $type
            ]);

        if(!$meta) {
            return false;
        }

        return $meta->getValue();
    }

}
