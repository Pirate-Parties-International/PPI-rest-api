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


}
