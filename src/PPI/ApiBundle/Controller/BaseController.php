<?php

namespace PPI\ApiBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;

class BaseController extends Controller
{
	protected $redis;

	public function getRedis() {
		if (empty($this->redis)) {
			$this->redis = $this->container->get('snc_redis.default');
		}
		return $this->redis;
	}

	public function getAllPartiesData() {
		$redis = $this->getRedis();

    	$keys = $redis->keys('ppi:orgs:*');
    	
    	$allData = array();
    	foreach ($keys as $key) {
    		$pdata = json_decode($redis->get($key));
    		$allData[strtolower($pdata->partyCode)] = $pdata;
    	}

    	return $allData;
	}

	public function getOnePartyData($id) {
		$redis = $this->getRedis();

    	$data = $redis->get('ppi:orgs:' . $id);
    	return json_decode($data);
	}

	public function getPartyLogo($id) {
		$redis = $this->getRedis();

    	$data = $redis->get('ppi:logos:' . $id);
    	return $data;
	}

	public function getCountryFlag($id) {
		$redis = $this->getRedis();

    	$data = $redis->get('ppi:flags:' . $id);	
    	return $data;
	}


}
