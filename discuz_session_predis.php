<?php
if(!defined('IN_DISCUZ')) {
	exit('Access Denied');
}

/**
 * @todo inet_pton��inet_ntop��windows��δʵ��,��linux�¿�������������������ip
 */
//��������Ա��redis set��key
define('OL_SET_INVISIBLE', getglobal('config/memory/prefix').'session_invisible');
//���uid>0�ҷ�����Ļ�Ա��sessionkey
define('OL_ZSET_CUSTOM', getglobal('config/memory/prefix').'session_zcustom');
//���uid>0�Ļ�ԱUID��SESSION��SID��ӳ��
define('OL_MAP_UID2SID', getglobal('config/memory/prefix').'session_uid2sid');
//��Ž����key
define('OLC_INVISIBLE', getglobal('config/memory/prefix').'onlinecount_invisible');
define('OLC_CUSTOM', getglobal('config/memory/prefix').'onlinecount_custom');
define('OLC_ALL', getglobal('config/memory/prefix').'onlinecount_all');
define('OL_LIST', getglobal('config/memory/prefix').'redis_onlinelist');

//�ڲ�֧��keys����ʱ������ʹ��������򼯺�ά�����ʱ��,�Ӷ���ȡ����sid
define('OL_ZSET_SID2LASTOP', getglobal('config/memory/prefix').'session_sid2lastop');

class discuz_session_predis extends discuz_session {

	private $newguest = array('sid' => 0, 'ip' => '',
		'uid' => 0, 'username' => '', 'groupid' => 7, 'invisible' => 0, 'action' => 0,
		'lastactivity' => 0, 'fid' => 0, 'tid' => 0, 'lastolupdate' => 0);

	private $old =  array('sid' =>  '', 'ip' =>  '', 'uid' =>  0);

	function discuz_session_predis($sid = '', $ip = '', $uid = 0) {
		parent::__construct($sid, $ip, $uid);
	}

	function set($key, $value) {
		if(isset($this->newguest[$key])) {
			$this->var[$key] = $value;
		}
	}

	function get($key) {
		if(isset($this->newguest[$key])) {
			return $this->var[$key];
		}
	}

	function init($sid, $ip, $uid) {
		$this->old = array('sid' =>  $sid, 'ip' =>  $ip, 'uid' =>  $uid);
		$session = array();
		if($sid) {
			$redisdata = self::_getsession($sid);
			if($redisdata && $redisdata['ip'] == $ip) $session = $redisdata;
		}
		/**
		 * ��ǰsession�����뵱ǰ�û�����Ӧʱ��ɾ�����session��������������û���½ʱ����
		 */
		if($session && $session['uid'] != $uid) {
			self::redis()->del(self::_mksessionkey($sid));
		}

		if(empty($session) || $session['uid'] != $uid) {
			$session = $this->create($ip, $uid);
		}

		$this->var = $session;
		$this->sid = $session['sid'];
	}

	function create($ip, $uid) {

		$this->isnew = true;
		$this->var = $this->newguest;
		$this->set('sid', random(6));
		$this->set('uid', $uid);
		$this->set('ip', $ip);//inet_pton($ip)
		if($uid) {
			self::redis()->hset(OL_MAP_UID2SID, $uid, $this->var['sid']);
			$this->invisible($uid, getuserprofile('invisible'));
		}
		$this->set('lastactivity', TIMESTAMP);
		$this->sid = $this->var['sid'];

		return $this->var;
	}

	function delete() {

		global $_G;
		$onlinehold = $_G['setting']['onlinehold'];
		$guestspan = 60;

		$onlinehold = TIMESTAMP - $onlinehold;
		$guestspan = TIMESTAMP - $guestspan;
		
		$session = self::_getsession($this->sid);
		if($session && $session['uid'] > 0){
			self::redis()->hdel(OL_MAP_UID2SID, $session['uid']);
			$session['invisible'] ? self::redis()->srem(OL_SET_INVISIBLE, $session['sid']) : self::redis()->zrem(OL_ZSET_CUSTOM, $this->sid);
		}
		self::redis()->del(self::_mksessionkey($this->sid));
	}

	function update() {
		global $_G;
		if($this->sid !== null) {

			$data = daddslashes($this->var);
			if($this->isnew) {
				$this->delete();
				if($this->var['uid'] > 0) {
					self::redis()->hset(OL_MAP_UID2SID, $this->var['uid'], $this->sid);
					self::redis()->zadd(OL_ZSET_CUSTOM, TIMESTAMP, $this->sid);
				}
			}
			self::_storage($this->sid, $data, $_G['setting']['onlinehold']);//ʹ�ú�̨���õ����߳���ʱ����Ϊ���ڷ�ֵ
			//�����˳���½ʱ
			if($_G['gp_action'] == 'logout' && $_G['session']['uid'] != $data['uid'] && $data['uid'] == 0) {
				self::redis()->hdel(OL_MAP_UID2SID, $_G['session']['uid']);
				self::redis()->srem(OL_SET_INVISIBLE, $_G['session']['sid']);
				self::redis()->zrem(OL_ZSET_CUSTOM, $_G['session']['sid']);
				self::redis()->zrem(OL_ZSET_SID2LASTOP, $_G['session']['sid']);
			}
			$_G['session'] = $data;
			dsetcookie('sid', $this->sid, 86400);
		}
	}

	/**
	 * @todo ʹ��ϵͳ�ƻ��������ɻ������ݣ�����ֻ�Ǽ򵥵Ķ�ȡ����.
	 * ���ڸ�����ʵ�ַ�����
	 * 1������ͨ��keys��ȡ����session���ݣ������������ԣ���ͬʱ�����û��϶�ʱ����ʮW������Ҫ����Ч�ʺͳ�ֲ��ԣ���������Ҫ����һ��ʱ�䣬�����ϵͳ�ƻ����������У�
	 * 2��ά��������ϡ���UID��0��set���������set����Ҫ�ڳ����߼���ά�������ϣ���������ʵʱ�ԡ�
	 *
	 * @param int(enum) $type 0�����У�1��uid>0���û�(�����ο�)��2���������û�
	 */
	function onlinecount($type = 0) {
		if(REDIS_SESSION_OLDATA_CACHE_TIME > 0) {
			if ($type == 1) return self::redis()->get(OLC_CUSTOM) + self::redis()->get(OLC_INVISIBLE);
			if ($type == 2) return self::redis()->get(OLC_INVISIBLE);
		} else {
			$custom = self::redis()->zcard(OL_ZSET_CUSTOM);
			$invisible = self::redis()->scard(OL_SET_INVISIBLE);
			if ($type == 1) return $custom + $invisible;
			if ($type == 2) return $invisible;
			self::cron_update_onlinecount();
		}
		return self::redis()->get(OLC_ALL);
	}
	
	/**
	 * ������������,Ӧ��ʹ�üƻ�������ô��߼�
	 * discuz cron�ű�Ϊsource/include/cron/cron_onlinecount_redis.php
	 * �������㣬������1W�˼ƣ�ÿ��session key����Լ��17��Ӣ���ַ��� 17Byte �׸�keys����ռ�� 170��000Byte��Ӧ�ÿ��Խ���
	 */
	function cron_update_onlinecount() {
if(REDIS_WITH_KEYS_METHOD):
		$keys = self::redis()->keys(REDIS_SESSION_PREFIX.'*');
		array_walk($keys, callback_strip_sessionkey_prefix, strlen(REDIS_SESSION_PREFIX));
		self::redis()->set(OLC_ALL, count($keys));
else:
		global $_G;
		$expiresids = self::redis()->zrangebyscore(OL_ZSET_SID2LASTOP, '-inf', TIMESTAMP - $_G['setting']['onlinehold']);
		if(empty($expiresids)) return;
		self::redis()->zremrangebyscore(OL_ZSET_SID2LASTOP, '-inf', TIMESTAMP - $_G['setting']['onlinehold']);
endif;

if(REDIS_WITH_KEYS_METHOD):
		//����OL_ZSET_CUSTOM�еĹ���session�������
		$tmpkeys = self::redis()->zrange(OL_ZSET_CUSTOM, 0, -1);
		$expiresids = array_diff($tmpkeys, $keys);
endif;
		if(!empty($expiresids)) {
			array_unshift($expiresids, OL_ZSET_CUSTOM);
			self::redis()->zrem($expiresids);
if(!REDIS_WITH_KEYS_METHOD):
			array_shift($expiresids);
endif;
		}

if(REDIS_WITH_KEYS_METHOD):
		//����OL_SET_INVISIBLE�еĹ���session�������
		$tmpkeys = self::redis()->smembers(OL_SET_INVISIBLE);
		$expiresids = array_diff($tmpkeys, $keys);
endif;
		if(!empty($expiresids)) {
			array_unshift($expiresids, OL_SET_INVISIBLE);
			self::redis()->srem($expiresids);
if(!REDIS_WITH_KEYS_METHOD):
			array_shift($expiresids);
endif;
		}

		//����OL_MAP_UID2SID�й��ڵ�����
		$tmpkeys = self::redis()->hgetall(OL_MAP_UID2SID);
		$tmpkeys = array_flip($tmpkeys);
if(REDIS_WITH_KEYS_METHOD):
		$expiresids = array_diff(array_keys($tmpkeys), $keys);
endif;
		$expireuid = array();
		foreach ($expiresids as $sid) {
			array_push($expireuid, $tmpkeys[$sid]);
		}
		/**
		 * @todo redis��hDelɾ�����fieldʱ���ƺ��������⣬�����д�����
		 */
		if(!empty($expireuid)) {
			array_unshift($expireuid, OL_MAP_UID2SID);
			self::redis()->hdel($expireuid);
		}

		unset($keys, $tmpkeys, $expiresids, $expireuid);
		//��������
		self::redis()->set(OLC_INVISIBLE, self::redis()->scard(OL_SET_INVISIBLE));
		self::redis()->set(OLC_CUSTOM, self::redis()->zcard(OL_ZSET_CUSTOM));
		
		//���������б���
		if(REDIS_SESSION_OLDATA_CACHE_TIME > 0) {
			$shoisonline = array();
			global $_G;
			self::onlinelist($_G['setting']['maxonlinelist'], $shoisonline);
			self::redis()->setex(OL_LIST, REDIS_SESSION_OLDATA_CACHE_TIME, serialize($shoisonline));
		}
	}
	
	/**
	 * @todo �û�״̬�л�ʱ��ͨ��session����������������ݡ����� member.php?mod=switchstatus �е��߼���Ҫ����
	 * �˴���������Ҫ�־û�
	 *
	 * @param int $uid
	 * @param boolean $status
	 */
	function invisible($uid, $status = false, $storage = false) {
		$this->set('invisible', $status);
		self::_invisible($uid, $this->var['sid'], $status);
		$storage && $this->update();
	}
	
	function _invisible($uid, $sid, $status = false) {
		if($status) {
			self::redis()->sadd(OL_SET_INVISIBLE, $sid);
			self::redis()->zrem(OL_ZSET_CUSTOM, $sid);
		} else {
			self::redis()->srem(OL_SET_INVISIBLE, $sid);
			self::redis()->zadd(OL_ZSET_CUSTOM, TIMESTAMP, $sid);
		}
	}
	
	function _mksessionkey($sid) {
		return REDIS_SESSION_PREFIX.$sid;
	}
	
	function _getsession($sid, $b = false){
		$redisdata = self::redis()->get($b ? $sid : self::_mksessionkey($sid));
		return $redisdata ? self::_unserialize($redisdata) : NULL;
	}
	
	/**
	 * �о��û������б���������̳��ҳ��ʾ
	 * �������ܲ���;���Ǽ��뻺��
	 */
	function onlinelist($max) {
		$keys = self::redis()->zrangebyscore(OL_ZSET_CUSTOM, '-inf', '+inf', 'limit', 0, $max);
		if(empty($keys)) return array();
		array_walk($keys, callback_add_sessionkey_prefix, REDIS_SESSION_PREFIX);
		$sessions = array_filter(self::redis()->mget($keys));
		array_walk($sessions, callback_session_unserialize);
		return $sessions;
	}
	
	function _unserialize($s){
		$s = unserialize($s);
		$s['ip'] = $s['ip'];//inet_ntop($redisdata['ip'])
		return $s;
	}
	
	function _uid2sid($uid){
		return self::redis()->hget(OL_MAP_UID2SID, $uid);
	}
	
	/**
	 * �����û������ݣ����̨�����û����û����Ӧ�õ��ô˷��������ڴ��໺���е�����
	 *
	 * @param int $uid
	 * @param int $gid
	 */
	function chgrp($uid, $gid){
		$sid = self::_uid2sid($uid);
		if(!$sid) return false;
		$session = self::_getsession($sid);
		if($session) {
			$session['groupid'] = $gid;
		}
		self::_storage($sid, $session);
	}
	
	function _storage($sid, $session, $expire=0) {
		$sessionkey = self::_mksessionkey($sid);
		if($expire > 0) {
			self::redis()->setex($sessionkey, $expire, serialize($session));
if(!REDIS_WITH_KEYS_METHOD):
			self::redis()->zadd(OL_ZSET_SID2LASTOP, TIMESTAMP, $sid);
endif;
		} else {
			self::redis()->set($sessionkey, serialize($session));
		}
	}
	
	/**
	 * ����UID��ȡsession,��һ�λ�ȡ���
	 *
	 * @param unknown_type $uid
	 * @return unknown
	 */
	public static function getsessionbyuid($uid) {
		if(is_array($uid)) {
			$sids = array_filter(self::redis()->hmget(OL_MAP_UID2SID, $uid));
			if(empty($sids)) return array();
			array_walk($sids, callback_add_sessionkey_prefix, REDIS_SESSION_PREFIX);
			$sessions = array_filter(self::redis()->mget($sids));
			array_walk($sessions, callback_session_unserialize);
			return $sessions;
		}
		return self::_getsession(self::_uid2sid($uid));
	}
	
	/**
	 * ���redis �������Ƿ���ã�predis�ṩ��isConnected()������ʹ��ping()�������м��;
	 *
	 * @return boolean
	 */
	public static function init_redis() {
		return self::redis()->isConnected();
	}
	
	public function getRedisClient() {
		return self::redis();
	}

	/**
	 * session����ʹ�õĴ洢����ʹ�ô��л�ѡ�
	 * ԭ��SID��UID����Ϊ�����ͣ������д洢��������������������
	 *
	 * @return memory_driver_redis
	 */
	private static function redis() {
		static $redis = NULL;
		if($redis === NULL) {
			require_once libfile('class/predis');
			global $_G;
			$conf = array(
			    'host'     => $_G['config']['sessionredis']['server'], 
			    'port'     => $_G['config']['sessionredis']['port']
			);
			if($_G['config']['sessionredis']['database'] > 0) {
				$conf['database'] = $_G['config']['sessionredis']['database'];
			}
			$redis = new Predis_Client($conf);
			try{
				$redis->connect();
			} catch (Exception $e) {
				/**
				 * @todo log something
				 */
			}
		}
		return $redis;
	}

	/**
	 * x2.5�ķ���
	 *
	 */
	public function count($type = 0) {
		return self::onlinecount($type);
	}

	/**
	 * ��ȡ�����û��ȣ�����֧��ԭ�湦��
	 * ���б�Ӧ�ý��л��棬�����ǰ�û��Ƿ��οͣ���Ӧ�öԵ�ǰ�û�����ʵʱ״̬���٣�������/����״̬�л�ʱ
	 * @todo �Ż�����������Ѱ���Լ���ѭ����������forum_index��ģ�鴦������߼���ֻ��ҪǶ����롣
	 *
	 * @param int $ismember �ٷ����壺1,uid > 0; 2,uid=0 [��Ҫ] Ϊ2���б����ο��б�Ŀǰ��֧��
	 * @param int $invisible �ٷ����壺1, invisible = 1 ; 2, invisible=0;
	 * @param unknown_type $start
	 * @param unknown_type $limit
	 * @return unknown
	 */
	public function fetch_member($ismember = 0, $invisible = 0, $start = 0, $limit = 0) {
		/**
		 * @todo ���û���ʱ�������ǰ�û�Ϊ��½״̬����ʹ���˻���ģʽ�Ļ���Ӧ���ڷ��صĻ��������в��뵱ǰ�û������߸�����������״̬
		 */
		$shoisonline = array();
		if(REDIS_SESSION_OLDATA_CACHE_TIME > 0) {
			$shoisonline = unserialize(self::redis()->get(OL_LIST));
			if($shoisonline) {
				global $_G;
				if($_G['uid']) {
					$findme = false;
					foreach ($shoisonline as $i => $online) {
						if($online['uid'] == $_G['uid']) {
							$shoisonline[$i]['invisible'] = $this->var['invisible'];
							$findme = true;
							break;
						}
					}
					if(!$findme) {
						array_shift($shoisonline, array($this->var));
					}
				}
				return $shoisonline;
			}
		}

		$shoisonline = self::onlinelist($start);
		return $shoisonline;
	}

	/**
	 * ��ȡ�����û�����
	 *
	 * @param int $type 1����0����
	 * @return unknown
	 */
	public function count_invisible($type = 1) {
		return $this->onlinecount($type == 1 ? 2 : 1);
	}
	
	public function update_by_ipban($ip1, $ip2, $ip3, $ip4) {
		return false;
	}

	public function update_max_rows($max_rows) {
		return false;
	}

	public function clear() {
		$keys = array();
if(REDIS_WITH_KEYS_METHOD):
		$keys = self::redis()->keys(REDIS_SESSION_PREFIX.'*');
endif;
		array_push($keys, OL_LIST);
		array_push($keys, OL_ZSET_CUSTOM);
		array_push($keys, OL_SET_INVISIBLE);
		array_push($keys, OL_MAP_UID2SID);
		array_push($keys, OLC_ALL);
		array_push($keys, OLC_CUSTOM);
		array_push($keys, OLC_INVISIBLE);
		array_push($keys, OL_ZSET_SID2LASTOP);
		self::redis()->del($keys);
	}
	
	public function count_by_fid($fid) {
		return 0;
	}

	/**
	 * @todo ���Կ���֧�ֻ��ڰ����ͳ�ƣ�����Ҫ����״̬��ά��
	 *
	 * @param unknown_type $fid
	 * @param unknown_type $limit
	 * @return unknown
	 */
	public function fetch_all_by_fid($fid, $limit) {
		return array();
	}
	
	public function fetch_by_uid($uid) {
		return self::getsessionbyuid($uid);
	}
	
	public function fetch_all_by_uid($uids, $start = 0, $limit = 0) {
		return self::getsessionbyuid($uids);
	}
	
	/**
	 * x25����
	 *
	 * @param unknown_type $uid
	 * @param unknown_type $data
	 */
	function update_by_uid($uid, $data) {
		$session = self::getsessionbyuid($uid);
		if(empty($session)) return false;

		foreach ($data as $k => $v) {
			if($k == 'invisible') self::_invisible($uid, $session['sid'], $v);
			if(key_exists($k, $session)) $session[$k] = $v;
		}
		self::_storage($session['sid'], $session);
	}
	
	public function count_by_ip($ip) {
		return 0;
	}

	public function fetch_all_by_ip($ip, $start = 0, $limit = 0) {
		return array();
	}

}

function callback_strip_sessionkey_prefix(&$item1, $key, $prefixlen) {
	$item1 = substr($item1, $prefixlen);
}

function callback_add_sessionkey_prefix(&$item1, $key, $prefix) {
	$item1 = $prefix.$item1;
}

function callback_session_unserialize(&$item1, $key, $prefix) {
	$item1 = discuz_session_predis::_unserialize($item1);
}
?>