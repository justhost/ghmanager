<?php
/*

Servers controller.
Main controller of the Panel. Almost all of the logic to manage servers.

Copyright (C) 2013 Nikita Bulaev

This library is free software; you can redistribute it and/or
modify it under the terms of the GNU Lesser General Public
License as published by the Free Software Foundation; either
version 2.1 of the License, or (at your option) any later version.

This library is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
Lesser General Public License for more details.

*/

 App::import('Vendor', 'ValveRcon', array('file'=>'valve_rcon.php'));
 App::import('Vendor', 'CommonRcon', array('file'=>'common_rcon.php'));
 App::import('Vendor', 'cod4Status', array('file'=>'COD4ServerStatus.php'));
 App::import('Vendor', 'SteamCondenser', array('file'=>'steam-condenser.php'));
 App::import('Xml');
 App::import('Core', 'HttpSocket');

 class ServersController extends AppController {

	public $name = 'Servers';

	public $layout = 'client';
	//public $_DarkAuth;
	public $helpers = array (
		'Time',
//		'Ajax',
		'javascript',
		'Html',
		'Text',
		'Js' => array('Jquery'),
		'Common'
	);

	public $components = array (
		'RequestHandler',
		'Session',
		'TeamServer',
		'KvParser'
	);

	 public $paginate = array(
        'limit' => 15,
        'order' => array(
            'Server.id' => 'desc'
        )
    );


	function checkRights($serverId = null)	{
	 	 if (intval($serverId) > 0){ // Server ID должен быть цифрой и больше нуля
	 	  /*
		  * Шаблон проверки принадлежности сервера пользователю
		  * Сначала получаем из сессии ID пользователя и группы.
		  * Потом сверяем этиже ID из данных сервера.
		  */

		 $this->loadModel('UserGroup');
		 $sessionUser = $this->UserGroup->findById($this->DarkAuth->getUserId());
		 $sessionUserId = $sessionUser['UserGroup']['id'];
		 $sessionUserGroup = $sessionUser['Group'][0]['id'];

		 $this->Server->unbindModel(array(
										'hasAndBelongsToMany' => array(
															'GameTemplate',
															'Type',
															'Plugin',
															'Location',
															'RootServer',
															'Service',
															'Order',
															'VoiceMumbleParam',
															'RadioShoutcastParam'
												)));

		 $this->Server->id = $serverId;
		 $server = $this->Server->read();

		 if ($server){
		 	// Проверим  - владееет ли пользователь сессии этим сервером?
		 	if ($server['User'][0]['id'] == $sessionUserId // Да, владеет
		 			||
		 		in_array($sessionUserGroup, array(1,2)) 						// Это администратор
		 			) {

		 			  return true;

		 			  }
		 	else // Нет, не владеет
		 	{
		 		$this->Session->setFlash('Вы не являетесь владельцем сервера с #'.$serverId.'.', 'flash_error');
		 		return false;
		 	}

		 }
		 else
		 {
		 		$this->Session->setFlash('Сервера с #'.$serverId.' не существует.', 'flash_error');
		 		return false;
		 }

	 	 }
	 	 else
	 	 {
	 	 		$this->Session->setFlash('Некорректный server ID.', 'flash_error');
		 		return false;
	 	 }

	}



	// Проверка параметров хостинга - пока заглушка
	function checkWebHosting( $param = null, $type = 'user'){

		if (!is_null($param)){

			$isppanel = @parse_ini_file("../config/external.ini.php", true);

			if (empty($isppanel)) {
				$this->Session->setFlash('Не могу получить данные. Попробуйте позднее. ', 'flash_error');
				return false;
			}

			$isplogin = $isppanel['isp']['login'];
			$isppass  = $isppanel['isp']['pass'];

			// Проверка наличия учётной записи клиента
			if ($type == 'user'){
				$data = "authinfo=".$isplogin.':'.$isppass.
						"&out=xml".
						"&func=user.edit" .
						"&elid="
						.$param
						;

				$response = $this->TeamServer->webGet('https://isp.teamserver.ru/manager/ispmgr', 0, $data, 'POST');

				if ($response !== false){

					$output = $this->parceXmlFromIsp($response);

					// Если есть ошибка, и код ошибки 3, то юзер еще не заведён
					if (!empty($output['Error'])){

						if ($output['Error']['code'] == 3 and $output['func'] == 'user.edit'){
							return 'none';
						}
						else
						{
							$this->Session->setFlash('Не удалось проверить учётную запись. Код ошибки ISP: '.$output['Error']['code'], 'flash_error');
							return false;
						}

					}
					else
					if (!empty($output['Info']))
					{
						$this->data = $output;
						return 'exists';
					}
					else
					{
						$this->Session->setFlash('Не удалось запросить данные о состоянии вашей учётной записи. Попробуйте позднее.', 'flash_error');
						return false;
					}

				}
				else
				{
					$this->Session->setFlash('Не удалось запросить данные о состоянии вашей учётной записи. Попробуйте позднее.', 'flash_error');
					return false;
				}
			}
			else
			if ($type == 'domain' || $type == 'wwwdomain')
			{
				$data = "authinfo=".$isplogin.':'.$isppass.
						"&out=xml".
						"&func=".$type.".edit" .
						"&elid="
						.$param
						;

				$response = $this->TeamServer->webGet('https://isp.teamserver.ru/manager/ispmgr', 0, $data, 'POST');

				if ($response !== false){

					$output = $this->parceXmlFromIsp($response);

					// Если есть ошибка, и код ошибки 3, то домен свободен
					if (!empty($output['Error'])){

						if ($output['Error']['code'] == 3 and @$output['func'] == $type.'.edit'){
							return 'none';
						}
						else
						{
							$this->Session->setFlash('Нельзя создать домен. Код ошибки ISP: '.$output['Error']['code'], 'flash_error');
							return false;
						}
					}
					else
					if (!empty($output['Info']))
					{
						$this->data = $output;
						return 'exists';
					}
					else
					{
						$this->Session->setFlash('Не удалось запросить данные о наличии домена. Попробуйте позднее.', 'flash_error');
						return false;
					}
				}
				else
				{
					$this->Session->setFlash('Не удалось запросить данные о домене. Попробуйте позднее.', 'flash_error');
					return false;
				}


			}
			else
			{
				return false;
			}
		}
		else
		{
			return false;
		}
	}

	/* SOURCE ENGINE QUERY FUNCTION, requires the server ip:port */
	function sourceQuery($HL2_address, $HL2_port = 27015, $timeout = 1){

	    $HL2_command = "\377\377\377\377TSource Engine Query\0";

	    $HL2_socket = fsockopen("udp://".$HL2_address, $HL2_port, $errno, $errstr,3);
	    fwrite($HL2_socket, $HL2_command);
	    stream_set_timeout($HL2_socket, 2);
	    $JunkHead = fread($HL2_socket,4);
	    $CheckStatus = stream_get_meta_data($HL2_socket);

	    if($CheckStatus["unread_bytes"] == 0)return false;

	    $do = 1;
	    $HL2_stats = "";
	    while($do){
	        stream_set_timeout($HL2_socket, $timeout);
	        $str = fread($HL2_socket,1);
	        $HL2_stats.= $str;
	        $status = stream_get_meta_data($HL2_socket);
	        if($status["unread_bytes"] == 0){
	               $do = 0;
	        }
	    }
	    fclose($HL2_socket);

	    $x = 0;
	    $result = "";
	    while ($x <= strlen($HL2_stats)){
	        $x++;
	        $result.= substr($HL2_stats, $x, 1);
	    }

	    // ord ( string $string );
	    $result = str_split($result);
	    $keys = array('name', 'map', 'dir', 'description', 'version');
		$info = array_fill_keys($keys, '');

	    $info['network'] = ord($result[0]);$char = 1;
	    while(ord($result[$char]) != "%00"){$info['name'] .= $result[$char];$char++;}$char++;
	    while(ord($result[$char]) != "%00"){$info['map'] .= $result[$char];$char++;}$char++;
	    while(ord($result[$char]) != "%00"){$info['dir'] .= $result[$char];$char++;}$char++;
	    while(ord($result[$char]) != "%00"){$info['description'] .= $result[$char];$char++;}$char++;
	    $info['appid'] = ord($result[$char].$result[($char+1)]);$char += 2;
	    $info['players'] = ord($result[$char]);$char++;
	    $info['max'] = ord($result[$char]);$char++;
	    $info['bots'] = ord($result[$char]);$char++;
	    $info['dedicated'] = ord($result[$char]);$char++;
	    $info['os'] = chr(ord($result[$char]));$char++;
	    $info['password'] = ord($result[$char]);$char++;
	    $info['secure'] = ord($result[$char]);$char++;
	    while(ord($result[$char]) != "%00"){$info['version'] .= $result[$char];$char++;}

	    return $info;
	}



	function codColorText ($text = null) {
		/*
			The color codes are:
			^1 = red
			^2 = green
			^3 = yellow
			^4 = blue
			^5 = light blue
			^6 = purple
			^7 = white
			^8 is a color that changes depending what level you are on.
			American maps = Dark Green
			Russian maps = Dark red/marroon
			British maps = Dark Blue
			^9 = grey
			^0 = black
		 */

		 $text = htmlspecialchars($text);
		 $codes  = array('^0','^1','^2','^3','^4','^5','^6','^7','^8','^9');
		 $colors = array('</font><font style="color: #000;">',
		 				 '</font><font style="color: red;">',
		 				 '</font><font style="color: green;">',
		 				 '</font><font style="color: orange;">',
		 				 '</font><font style="color: blue;">',
		 				 '</font><font style="color: cyan;">',
		 				 '</font><font style="color: grey;">',
		 				 '</font><font style="color: purple;">',
		 				 '</font><font style="color: marroon;">',
		 				 '</font><font style="color: grey;">');
		 $coloredTxt  = "<font>";
		 $coloredTxt .= str_replace($codes,$colors, $text);
		 $coloredTxt .= "</font>";

		 return $coloredTxt;
	}
	/*
	 * Функция для проверки ввода запрещенных параметров сервера
	 */
	function checkForBlockedParam ( $param = null){
			$blockedParam = array( 'set net_ip',
					               'set net_port',
					               'set sv_maxclients',
					               'set ui_maxclients',
					               'set sv_fps',
					               'maxclients',
					               'pingboost',
					               'tickrate',
					               'sys_tickrate',
					               'fps_max',
					               'host',
					               'port',
					               'oldqueryportnumber');
			if (array_search( strtolower($param), $blockedParam)){
				return true;
			}
			else
			{
				return false;
			}
	}

	/*
	 * Парсинг отклика сервера, преобразованного в xml array
	 * на ошибки и лог и вывод в виде строк
	 */
	function parseXmlResponse ($xmlAsArray = null){
		$output = array('error' => '', 'log' => '');
		// Парсинг ошибок
 		if (!empty($xmlAsArray['Response']['Error']) and count($xmlAsArray['Response']['Error']) > 1){
 			foreach ( $xmlAsArray['Response']['Error'] as $error ) {
				if (!is_array($error)){
					$output['error'] .= $error.'<br/>';
				}
			}
 		}
 		else
 		if (!empty($xmlAsArray['Response']['Error']) and count($xmlAsArray['Response']['Error']) == 1){
			$output['error'] .= $xmlAsArray['Response']['Error'].'<br/>';
 		}
 		else if (!empty($xmlAsArray['Response']['error'])){
 			$output['error'] = $xmlAsArray['Response']['error'];
 		}

 		// Парсинг Лога
 		if (!empty($xmlAsArray['Response']['Log'])){
 			foreach ( $xmlAsArray['Response']['Log'] as $logString ) {
				$output['log'] .= $logString.'<br/>';
			}
 		}
 		else if (!empty($xmlAsArray['Response']['log'])){
 			$output['log'] = $xmlAsArray['Response']['log'];
 		}

 		return $output;
	}


	/* Парсинг XML-ответов от ISP Manager*/
	function parceXmlFromIsp($response = null){

		if (!is_null($response)){
			$xml = new Xml($response);
			$xmlAsArray = $xml->toArray();

			// Если есть ошибка, возвращаем только её
			if (!empty($xmlAsArray['Doc']['Error'])){
				$return = $xmlAsArray['Doc'];
				return $return;
			}
			else
			{
				$return['Info'] = $xmlAsArray['Doc'];
				return $return;
			}

		}
		else
		{
			return false;
		}

	}

	// Функция рассчитывает значение для рисования графической
	// линейки до окончания определнной даты
	function scaleDate ($date){

		$unixTo = strtotime($date);
		$currentTo = time();

		if (($unixTo - $currentTo) >= 2592000){
			$scale = 1;
		}
		else if (($unixTo - $currentTo) <= 0)
		{
			$scale = 0;
		}
		else
		{
			$scale = round(($unixTo - $currentTo) / 2592000, 2);
		}

		return $scale;

	}

	/*
	 * $period = all - запросить все генерируемы графики
	 * $period = 24h - запросить график за 24 часа
	 */

	function getStatGraph ($id = null, $period = 'all'){
		$this->DarkAuth->requiresAuth();

		if ($this->checkRights($id)){

			$this->loadModel('ServerClean');

			$this->ServerClean->id = $id;
			$server = $this->ServerClean->read();

			if ($period == 'all'){
				$periods = array('24h', '7d');
			}
			else
			{
				$periods = array($period);
			}

			// Получить кодированное имя файла
			// Код шифрования:
			$graphKey = 'sjadKJHQWdhhwoepf';
			$graphs = array();

			foreach ( $periods as $period ) {

				// Имя файла будет строка из секретного ключа + ID сервера + период,
	            // закодированные в MD5

	            $graphFileName = md5( $graphKey.$id.$period ).'.png';

				// Проверить на наличие изображения графика

				$fullGraphUrl = 'http://'.$server['ServerClean']['address'].'/gamestats/'.$graphFileName;
				$response = get_headers($fullGraphUrl, 1);


				if ($response[0] == 'HTTP/1.1 200 OK'){
					$graphs[$period] = $fullGraphUrl;
				}
			}

			if (!empty($graphs)){
				return $graphs;
			}
			else
			{
				return false;
			}
		}
	}

	private function mapDesc( $gameTemplateId = null, $map = null ){
		// Описание карты
		// Нефиг запрашивать лишнюю информацию из базы
		$this->Server->GameTemplate->unbindModel( array( 'hasAndBelongsToMany' => array(
																	'Mod',
																	'Plugin',
																	'Config',
																	'Service',
																	'Server'
														)));
		$this->Server->GameTemplate->bindModel( array( 'hasAndBelongsToMany' => array(
																	'Map' => array(
																					'fields' => 'id, name, longname, desc, official, map_type_id',
																					'conditions' => array('name' => $map)
																				   )
																			   )));

		$this->Server->GameTemplate->id = $gameTemplateId;
		$gameTemplate = $this->Server->GameTemplate->read();

		//pr($gameTemplate);
		if (!empty($gameTemplate['Map'][0]))
		{
			$mapDesc = $gameTemplate['Map'][0];

			// Проверить на наличие изображения карты
			if (file_exists(WWW_ROOT.'/img/gameMaps/'.$mapDesc['id'].'.jpg')){
				$mapDesc['image'] = $mapDesc['id'];
			}
			else
			{
				$mapDesc['image'] = NULL;
			}

			return $mapDesc;
		}
		else
		{
			return false;
		}
	}

	function beforeRender() {
		$this->Server->User->unbindModel(array(
											'hasAndBelongsToMany' => array(
																'Server',
																'SupportTicket'
													)));

		$this->Server->User->id = $this->DarkAuth->getUserId();

		$userInfo = $this->Server->User->read();

		if (!empty($userInfo)){
			unset(  $userInfo['User']['passwd'],
					$userInfo['User']['tokenhash']);

			// Убрать все теги, xss-уязвимость
			foreach ( $userInfo['User'] as $key => $value ) {
       				$userInfo['User'][$key] = strip_tags($value);
			}

			$this->set('userinfo', $userInfo);
		}

		Cache::set(array('duration' => '+1 days'));

		if (($helpers = Cache::read('helpers')) === false) {

			$this->loadModel('Help');
			$helpers = $this->Help->find('list');

			Cache::set(array('duration' => '+1 days'));
			Cache::write('helpers', $helpers);
		}

		$this->set('helpers', $helpers);
	}

	function index() {
		$this->DarkAuth->requiresAuth();
		$this->set('title_for_layout', 'Администрирование серверов');
		$this->loadModel('GameTemplateType');
		$this->loadModel('Eac');


		$this->Server->User->unbindModel(array(
										'hasAndBelongsToMany' => array(
															'SupportTicket'
												)));

		$this->Server->User->bindModel(array(
										'hasMany' => array(
													'Actions' => array (
																			'className' => 'Actions',
																			'foreignKey' => 'user_id',
																			'dependent' => false,
																			'limit' => '15',
																			'order' => ' created DESC'
																		)
														)));

		$this->Server->User->id = $this->DarkAuth->getUserId();
		$user = $this->Server->User->read();

		$serverIds = $user['Server'];

		$serversIdsList = array();
		foreach ($user['Server'] as $serverId):
			$serversIdsList[] = $serverId['id'];
		endforeach;

		// Переходим к серверам
		// Нефиг запрашивать лишнюю информацию из базы
		$this->Server->unbindModel(array(
										'hasAndBelongsToMany' => array(
															'Plugin',
															'Location',
															'RootServer',
															'Service',
															'Order',
															'User',
															'VoiceMumbleParam',
															'RadioShoutcastParam'
												)));

		if (!empty($serversIdsList) and count($serversIdsList) > 15)
		{
			$this->paginate =  array(   'conditions' => array('id'=>$serversIdsList) );
			$userServers = $this->paginate('Server');
			$this->set('paginate', true);
		}
		else
		{
			$userServers = $this->Server->find('all',array(
											'conditions' => array('id'=>$serversIdsList)));
		}

		// Обнулить переменные, на всякий случай
		$servers  = array();
		$tmp = array();
		$serversIds = ''; // Обнулить список ID серверов, для которых будем запрашивать статус
		$eac = array();
		$eacStatus = array();
		$serverIps = array();
		foreach ($userServers as $server):

			$serverIps[] = $server['Server']['address'].':'.$server['Server']['port'];
			$tmp['Server'] = $server['Server'];
			$tmp['Type']   = $server['Type'];
			$tmp['GameTemplate'] = $server['GameTemplate'];
			$tmp['Mod'] = $server['Mod'];

			if (time() < strtotime($server['Server']['payedTill'])
					&&
				$server['Server']['initialised'] == 1 ){
				// В список серверов, у которых запросим статус, внести только оплаченные
				// и инициализированные, чтоб не гонять лишние запросы
				$serversIds .= ':'.strval($server['Server']['id']);

			}

			// Рассчет графика окончания аренды
			$tmp['Server']['scaleTime'] = $this->scaleDate($tmp['Server']['payedTill']);
			$tmp['Server']['name'] = strip_tags($tmp['Server']['name']); //XSS

			// Теперь создадим массивы, из типов серверов
			switch (@$server['Type'][0]['name']){
				case 'srcds':
				case 'hlds':
				case 'cod':
				case 'ueds':
				case 'game':
					$servers['Game'][] = $tmp;
					break;
				case 'voice':
					$servers['Voice'][] = $tmp;
					break;
				case 'eac':
					$servers['Eac'][] = $tmp;

					$eac = $this->Eac->findByserverId($tmp['Server']['id']);

					if (!empty($eac))
					{
						$eacStatus[$tmp['Server']['id']] = $eac['Eac']['active'];
					}

					break;
				case 'radio':
					$servers['Radio'][] = $tmp;
					break;
			}

		endforeach;
		//pr($servers);

		/* Теперь подготовим данный для автоматического
		 * старта процедуры заказа, если человек приходит
		 * по ссылке с сайта
		 * */

		if ($this->Session->read('orderFromSite')){
			$newOrder = $this->Session->read('orderFromSite');

			$template = $this->GameTemplateType->findByName($newOrder['template']);
			$this->set('newOrderTemplate', $template);
			$this->Session->delete('orderFromSite');
		}

		$this->set('serversGrouped', $servers);
		$this->set('serversIds',$serversIds);
		$this->set('journal', $user['Actions']);
		$this->set('eacStatus', $eacStatus);

		// Журнал Атак
		Cache::set(array('duration' => '+20 minutes'));

		if (($logs = Cache::read('iptablesLogsForUser'.$user['User']['id'])) === false) {

			$redis = $this->TeamServer->redisConnect(10);
			$redis->multi();

			// Запрос по 10 логов с каждого IP
			foreach ($serverIps as $serverIp) {
				$redis->lRange('dst:'.$serverIp, -10, -1);
			}

			$logRanges = $redis->exec();

			$logIds = array();
			// Суммируем все ID логоа
			foreach ($logRanges as $logRange) {
				if (!empty($logRange))
				{
					$logIds = array_unique(array_merge($logIds, $logRange));
				}
			}

			// Обратная сортировка по ID лога
			rsort($logIds);

			$redis->multi();

			// Запрос логов
			foreach ($logIds as $logId) {
				$redis->lRange('log:'.$logId, -5, -1);
			}

			$logs = $redis->exec();

			// Очистка пустых значений
			foreach ($logs as $key => $log) {
				if (empty($log))
				{
					unset($logs[$key]);
				}
			}

			// Обрезка до 25
			$logs = array_slice($logs, 0, 25);

			Cache::set(array('duration' => '+20 minutes'));
			Cache::write('iptablesLogsForUser'.$user['User']['id'], $logs);
		}

		$this->set('iptablesLog', $logs);

	}

	function orderFromSite ($game) {
		$flash = "Перед оформлением заказа требуется пройти очень короткую регистрацию.";
		$order = array(
						'template' => $game

						);
		$this->Session->write('orderFromSite', $order);
		$this->DarkAuth->requiresAuth(array(),'',$flash);
		$this->redirect(array('action'=>'index'));

	}

	function add() {
		$this->DarkAuth->requiresAuth();
		$this->loadModel('Type');
		$this->loadModel('Mod');

		if (!empty($this->data)) {
			$this->data['User']['id'] = $this->DarkAuth->getUserID();
			//Возвращаемся на страницу администрирования, если юзер администратор
			$userInfo = $this->DarkAuth->getAllUserInfo();
			if ($userInfo['Group'][0]['id'] == ('1' || '2')) {
				$redirTo = 'control';
			}
			else
			{
				$redirTo = 'add';
			}

			// Сразу внести карту сервера по-умолчанию, если сервер игровой
			$this->Server->GameTemplate->unbindModel(array(
											'hasAndBelongsToMany' => array(
																'Mod',
																'Plugin',
																'Config',
																'Service',
																'Server'
													)));
			$this->Server->GameTemplate->id = $this->data['GameTemplate']['id'];
			$gameTemplate = $this->Server->GameTemplate->read();
			if (!empty($gameTemplate)){
				$this->data['Server']['map'] = $gameTemplate['GameTemplate']['defaultMap'];
			}

			/***** Установить fps *************************************************/
			if ($gameTemplate['Type'][0]['id'] == 1
					or
				$gameTemplate['Type'][0]['id'] == 5 ){

					if ($gameTemplate['GameTemplate']['name'] != 'l4d'
							and
						$gameTemplate['GameTemplate']['name'] != 'l4d2'){
							if ($this->data['Server']['slots'] > 0 and $this->data['Server']['slots'] <= 10){
								$this->data['Server']['fpsmax'] = 1000;
							}
							else
							if ($this->data['Server']['slots'] > 10 and $this->data['Server']['slots'] <= 24){
								$this->data['Server']['fpsmax'] = 500;
							}
							else
							{
								$this->data['Server']['fpsmax'] = 300;
							}

						}

				}
			/********************************************************************/
			if (@$this->Server->save($this->data)) {
				$serverId = $this->Server->id;
				$this->Session->setFlash('Заявка успешно отправлена.<br/>Ожидание оплаты.', 'flash_success');
				$this->redirect(array('controller'=>'orders','action' => $redirTo,$serverId));
			} else {
				$this->Session->setFlash('Возникла ошибка:<br/>'.mysql_error(), 'flash_error');
			}
		}
		else
		{

				//********************************************************
				//берем полный список типов серверов
				$typesList = $this->Type->find('list',array('fields' => array('id','longname')));
				asort($typesList);
				$this->set('typesList', $typesList);
				//Если шаблон уже имеет тип
					if (@$this->data['Type'][0]['id']){
						$this->set('typeId', @$this->data['Type'][0]['id']);
					}
					else
					{
						//Выставить пункт списка по умолчанию на SRCDS
						$this->set('typeId', '1');
					}
				// Конец выбора типов серверов
				//********************************************************
				//********************************************************
				//берем полный список шаблонов серверов
				$this->Server->GameTemplate->unbindModel(array(
													'hasAndBelongsToMany' => array(
																		'Type',
																		'Mod',
																		'Plugin',
																		'Config',
																		'Service',
																		'Server'
															)), false);

				$gameTemplatesList = $this->Server->GameTemplate->find('list',array('fields' => array('id','longname')));
				$this->set('gameTemplatesList', $gameTemplatesList);
				//Если сервер уже имеет вариант
				if (@$this->data['GameTemplate'][0]['id']){
					$this->set('gameTemplatesId', @$this->data['GameTemplate'][0]['id']);
				}
				else
				{
					//Выставить пункт списка по умолчанию на l4d
					$this->set('gameTemplatesId', '1');
				}
				// Конец выбора шаблонов серверов
				//********************************************************
				//********************************************************
				//берем полный список модов серверов
				/* TODO:
				 * Привести тут выбор модов к общему знаменателю с заказами и редактором сервера,
				 * а именно, чтобы показывались только привязанные к шаблону моды
				 */
				$mods = $this->Mod->find('all');
				foreach ( $mods as $mod ) {
       				$modsList[$mod['Mod']['id']] = $mod['Mod']['longname']." ".$mod['Mod']['version'];
				}
				$this->set('modsList', $modsList);
				//Если сервер уже имеет вариант
				if (@$this->data['Mod'][0]['id']){
					$this->set('modsId', @$this->data['Mod'][0]['id']);
				}
				else
				{
					//Выставить пункт списка по умолчанию на Ваниллу
					$this->set('modsId', '1');
				}
				// Конец выбора вариантов серверов
				//********************************************************

				// Кусочек скрипта для организации ползунка выбора слотов
				$script="";
				$i=1;

				Cache::set(array('duration' => '+2 hours'));
				if (($gameTemplates = Cache::read('gameTemplatesCleanAll')) === false) {

					$gameTemplates = $this->Server->GameTemplate->find('all');

					Cache::set(array('duration' => '+2 hours'));
					Cache::write('gameTemplatesCleanAll', $gameTemplates);
				}

				foreach ($gameTemplates as $gameTemplate):
					if ($i>1){
						$script.="\n else ";
					}
					$script.="if (selectedGame == '".$gameTemplate['GameTemplate']['id']."'){
									//".$gameTemplate['GameTemplate']['name']."
									v = ".$gameTemplate['GameTemplate']['slots_value'].";
									mi = ".$gameTemplate['GameTemplate']['slots_min'].";
									ma = ".$gameTemplate['GameTemplate']['slots_max'].";
								}\n";
					$i++;
				endforeach;
				$script.="else {

								var v = 8;
								var mi = 8;
								var ma = 32;
							}\n";
				$this->set('script', $script);
		}
	}

	function edit($id = null, $action = null) {
		$this->DarkAuth->requiresAuth(array('Admin','GameAdmin'));
		$this->loadModel('Type');
		$this->loadModel('ServerBelong');
		$this->Server->id = $id;

		if (empty($this->data) and is_null($action)) {

			$this->Session->setFlash('При изменении слотов, аренда не пересчитывается!<br/> Считайте вручную или меняйте слоты через шестерёнку!', 'flash_success');

			$this->Server->bindModel(array(
											'hasAndBelongsToMany' => array(
																'Order' => array( 'order' => 'id DESC' )
											)));

			$this->data = $this->Server->read();

			$this->data['Server']['username'] = $this->data['User'][0]['username'];
			//*******************************************************
			//берем полный список физических серверов
			$rootServersList = $this->Server->RootServer->find('list');
			$rootServersList[0] = 'Выбрать...';
			$this->set('rootServersList', $rootServersList);
			//Если сервер уже привязен к физическому
			if (@$this->data['RootServer'][0]['id']){
				$this->set('rootServersId', $this->data['RootServer'][0]['id']);
			}
			else
			{
				//Выставить пункт списка по умолчанию на Выбрать
				$this->set('rootServersId', '0');
			}
			// Конец выбора физических серверов
			//********************************************************

			//********************************************************
			//берем полный список типов серверов
			$typesList = $this->Type->find('list',array('fields' => array('id','longname')));
			asort($typesList);
			$this->set('typesList', $typesList);
			//Если шаблон уже имеет тип
				if (@$this->data['Type'][0]['id']){
					$this->set('typeId', @$this->data['Type'][0]['id']);
				}
				else
				{
					//Выставить пункт списка по умолчанию на SRCDS
					$this->set('typeId', '1');
				}
			// Конец выбора типов серверов
			//********************************************************
			/*
			 * Создать список типов приватных серверов
			 * */

			$typeDiscount = array(
									'0' => 'Публичный сервер',
									'1' => 'Приватный с паролем',
									'2' => 'Приватный с автоотключением'
								  );

			$this->set('typeDiscount', $typeDiscount);
			//Если сервер уже иммет приватный статус
			if (@$this->data['Server']['privateType']){
				$this->set('privateTypeId', $this->data['Server']['privateType']);
			}
			else
			{
				//Выставить пункт списка по умолчанию на Выбрать
				$this->set('privateTypeId', '0');
			}
			//********************************************************
			//берем полный список шаблонов серверов
			$this->Server->GameTemplate->unbindModel(array(
											'hasAndBelongsToMany' => array(
																'Type',
																'Mod',
																'Plugin',
																'Config',
																'Service',
																'Server'
													)), false);

			$gameTemplatesList = $this->Server->GameTemplate->find('list', array('fields' => array('longname')));
			$this->set('gameTemplatesList', $gameTemplatesList);
			//Если сервер уже имеет вариант
			if (@$this->data['GameTemplate'][0]['id']){
				$this->set('gameTemplateId', @$this->data['GameTemplate'][0]['id']);
			}
			else
			{
				//Выставить пункт списка по умолчанию на Ваниллу
				$this->set('gameTemplateId', '1');
			}
			// Конец выбора шаблонов серверов
			//********************************************************

			// Кусочек скрипта для организации ползунка выбора слотов
				$script="";
				$i=1;

				Cache::set(array('duration' => '+2 hours'));
				if (($gameTemplates = Cache::read('gameTemplatesCleanAll')) === false) {

					$gameTemplates = $this->Server->GameTemplate->find('all');

					Cache::set(array('duration' => '+2 hours'));
					Cache::write('gameTemplatesCleanAll', $gameTemplates);
				}


				foreach ($gameTemplates as $gameTemplate):
					if ($i>1){
						$script.="\n else ";
					}
					$script.="if (selectedGame == '".$gameTemplate['GameTemplate']['id']."'){
									//".$gameTemplate['GameTemplate']['name']."
									v = ".$this->data['Server']['slots'].";
									mi = ".$gameTemplate['GameTemplate']['slots_min'].";
									ma = ".$gameTemplate['GameTemplate']['slots_max'].";
								}\n";
					$i++;
				endforeach;
				$script.="else {

								var v = 8;
								var mi = 8;
								var ma = 32;
							}\n";
				$this->set('script', $script);

			$moneyLeft = $this->TeamServer->countServerMoneyLeft($this->data);

			$this->set('moneyLeft', $moneyLeft[0]);
			$this->set('moneyPerDay', $moneyLeft[1]);
			$this->set('dayLeft', $moneyLeft[2]);


		}
		else
		if ($action == 'moneyToAcc') // Перебросить остаток средств на счёт
		{

			$this->Server->bindModel(array(
											'hasAndBelongsToMany' => array(
																'Order' => array( 'order' => 'id DESC' )
											)));

			$this->data = $this->Server->read();

			/*	 - Остановить сервер
				 - Создать проводку в Bills
				 - Обновить счёт клиента
				 - Прописать текущее время в базу минус один час, чтобы не было лишних уведомлений клиенту
			*/

			if ($this->script($id, 'stop'))
			{
				$this->loadModel('Bill');

				$moneyLeft = $this->TeamServer->countServerMoneyLeft($this->data);

				if ($moneyLeft[0] > 0)
				{
					$bill['Bill']['user_id'] = $this->data['User'][0]['id'];
					$bill['Bill']['sumPlus'] = $moneyLeft[0];
					$bill['Bill']['sumPlusReal'] = $moneyLeft[0];
					$bill['Bill']['payedBy'] = 'internal';
					$bill['Bill']['desc'] = 'Остаток от сервера #'.$id;

					if ($this->Bill->save($bill))
					{
						$this->Server->User->id = $this->data['User'][0]['id'];

						if ($this->Server->User->saveField('money', round($this->data['User'][0]['money'], 2) + $moneyLeft[0]))
						{
							$newPayedTill = date('Y-m-d H:i:s',  mktime(date('H')-1,
																 59,
																 59,
																 date('m'),
																 date('d'),
																 date('Y')));

							if ($this->Server->saveField('payedTill', $newPayedTill))
							{
								$this->Session->setFlash('Остаток средств с сервера переведены на счёт.', 'flash_success');
							}
							else
							{
								$this->Session->setFlash('Ошибка при изменении срока аренды. '.mysql_error(), 'flash_error');
							}
						}
						else
						{
							$this->Session->setFlash('Ошибка при сохранении остатка средств в профиле клиента. '.mysql_error(), 'flash_error');
						}
					}
					else
					{
						$this->Session->setFlash('Ошибка при сохранении проводки. '.mysql_error(), 'flash_error');
					}
				}

				$this->redirect($this->referer());
			}

		}
		else
		{
			// Если привязка не установлена и сервер не EAC, жестко убрать связь и убрать ключ инициализации
			if ($this->data['RootServer']['id'] == 0
					and
				!in_array($this->data['GameTemplate']['id'], array('37', 38))

				)
			{
					$this->data['RootServer']['id'] = NULL;
					$this->data['Server']['initialised'] = '0';
			}

			// Вычленим id пользователя
			$user = $this->User->findByUsername($this->data['User'][0]['username']);
			if (!empty($user)){
				$this->data['User']['id'] = $user['User']['id'];
			}
			//pr($this->data);
			$this->data['ServerBelong'] = $this->data['Server'];

			if ($this->ServerBelong->saveAll($this->data)) {
				$this->Session->setFlash('Информация о сервере обновлена.', 'flash_success');
				$this->redirect($this->referer());
			}
		}
	}
	// Смена шаблона сервера пользователем
	function changeGame ( $id = null){
		/* Ох, забавный будет алгоритм
		 * 1) Проверить, подключена ли услуга "Смена игры сервера" у клиента
		 * 2) Если нет сохранённых данных сервера с запрашиваемым шаблоном в ServerStore
		 * 		I)   Подготовить данные к переносу с учётом модов, плагинов,
		 * 		     типа, шаблона и корневого сервера.
		 * 		II)  Скопировать данные
		 * 		III) Пересчитать срок аренды исходя из новой стоимости слотов
		 * 		IV)  Выключить сервер, если в статусе в базе exec_success
		 * 		V)   Прописать новый тип, шаблон и срок аренды, удалить привязку к модам и плагинам
		 * 		VI)  Включить переинициализацию
		 * 3) Если уже есть сохранённые данные этого сервера с запрашиваемым шаблоном:
		 * 		I)    Подготовить данные к переносу с учётом модов, плагинов,
		 * 		      типа, шаблона и корневого сервера.
		 * 		II)   Скопировать данные
		 * 		III)  Удалить привязку текущего сервера к типу, шаблону, модам и плагинам
		 * 		IV)   Пересчитать срок аренды исходя из новой стоимости слотов
		 * 		V)    Выключить сервер, если в статусе в базе exec_success
		 * 		VI)   Подготовить новые данные к обратному переносу
		 * 		VII)  Скопировать данные
		 * 		VIII) Удалить эти данные из ServerStore
		 */
		//$this->layout = 'ajax';
		$this->DarkAuth->requiresAuth();

		if ($this->checkRights($id)){
			$this->loadModel('ServerStore');
			// Нефиг запрашивать лишнюю информацию из базы
			$this->Server->unbindModel(array(
											'hasAndBelongsToMany' => array(
																'Order',
																'User',
																'VoiceMumbleParam',
																'RadioShoutcastParam'
													)));
			// Надо посмотреть, не использовалась ли услуга за последние сутки
			$this->Server->bindModel(array(
									'hasOne' => array(
												'UsedService' => array(
																		'conditions' => array ('service_id' => '3'))
													)));
			$this->Server->id = $id;
			$server = $this->Server->read();
			// Время и дата, когда можно повотрно использовать услугу
			if (!empty($server['UsedService']['date_used'])){
				$minTimeToUseService = strtotime($server['UsedService']['date_used'].'+ 1 day');
			}
			else
			{
				$minTimeToUseService = 0;
			}
			//Проверить наличие услуги
			if(!empty($server['Service'])){
				// Составить список подключенных услуг
				foreach ( $server['Service'] as $service ) {
       				$serviceIds[] = $service['id'];
				}
			}
			/* Если подключена услуга "Смена игры сервера" id = 3 ,
			 * а также прошли сутки с момента последнего её использования */
			if (time() > $minTimeToUseService
					and
				!empty($serviceIds)
					and
				!empty($this->data['GameTemplate']['id'])
					and
				in_array('3', $serviceIds)
				){

				$this->loadModel('ServerCore');
				// Обнулить переменные
				$modsList    = array();
				$pluginsList = array();
				$serverStore = array();

				$server['Server']['orig_id'] = $server['GameTemplate'][0]['name'].'_'.$id;
				unset($server['Server']['id']);
				$serverStore['ServerStore']  = $server['Server'];
				$newServer['ServerCore'] = $serverStore['ServerStore'];
				$serverStore['Type']['id']   = $server['Type'][0]['id'];
				$serverStore['RootServer']['id']   = $server['RootServer'][0]['id'];
				$serverStore['GameTemplate']['id'] = $server['GameTemplate'][0]['id'];

				// Список модов
				foreach ( $server['Mod'] as $mod ) {
		       		$modsList['Mod'][] = $mod['id'];
				}
				// Список плагинов
				foreach ( $server['Plugin'] as $plugin ) {
		       		$pluginsList['Plugin'][] = $plugin['id'];
				}

				$serverStore['Mod']    = $modsList;
				$serverStore['Plugin'] = $pluginsList;
				// Сначала надо остановить сервер
				if ($this->Script($id,'stop')){
					$this->Server->GameTemplate->id = $server['GameTemplate'][0]['id'];
					$currentGameTemplate = $this->Server->GameTemplate->read();

					$this->Server->GameTemplate->id = $this->data['GameTemplate']['id'];
					$newGameTemplate = $this->Server->GameTemplate->read();

					// Если порты протоколов не совпадают, надо обнулить порт сервера

					if ($currentGameTemplate['Protocol'][0]['port'] != $newGameTemplate['Protocol'][0]['port']){
						$newPort = NULL;
						$serverStore['ServerStore']['port'] = NULL;
					}
					else
					{
						$newPort = $server['Server']['port'];
					}

					// Сохраняем в базу для временного хранения
					if ($this->ServerStore->save($serverStore)){

						// Рассчёт нового срока аренды
						// Всё считаем в unixtimestamp
						$rentEnd   = strtotime($server['Server']['payedTill']);
						$rentStart = strtotime($server['Server']['payedTill'].' - 1 month');
						$rentNow   = time();
						if ($server['Server']['privateType'] == 1){ // приватный с паролем
							$pricePerMonthCurrent = $server['Server']['slots']*$server['GameTemplate'][0]['pricePrivatePassword'];
							$pricePerMonth = $server['Server']['slots']*$newGameTemplate['GameTemplate']['pricePrivatePassword'];
						}
						else
						if ($server['Server']['privateType'] == 2){ // приватный с автоотключением
							$pricePerMonthCurrent = $server['Server']['slots']*$server['GameTemplate'][0]['pricePrivatePower'];
							$pricePerMonth = $server['Server']['slots']*$newGameTemplate['GameTemplate']['pricePrivatePower'];
						}
						else
						{
							$pricePerMonthCurrent = $server['Server']['slots']*$server['GameTemplate'][0]['price'];
							$pricePerMonth = $server['Server']['slots']*$newGameTemplate['GameTemplate']['price'];
						}

						$sumLeft = $pricePerMonthCurrent*( ($rentEnd - $rentNow)/($rentEnd - $rentStart) );
						$newSumFromDate = $pricePerMonth*( ($rentEnd - $rentNow)/($rentEnd - $rentStart) );

						$rentTimeLeft = round(($sumLeft/$newSumFromDate)*($rentEnd - $rentNow), 0);
						$newPayedTill =  date('Y-m-d H:i:s', $rentNow + $rentTimeLeft);



						$this->ServerCore->id = $id;

						// Пробуем найти уже сохранённые данные требуемого шаблона
						$savedServer = $this->ServerStore->findByOrigId($newGameTemplate['GameTemplate']['name'].'_'.$id);

						// Подключить модель для установки даты использования услуги
						$this->loadModel('UsedService');
						$serviceUsed['UsedService']['date_used'] = date('Y-m-d H:i:s', time());
						$serviceUsed['UsedService']['server_id'] = $id;
						$serviceUsed['UsedService']['service_id'] = '3';

						if (!empty($server['UsedService']['id'])){
							$this->UsedService->id = $server['UsedService']['id'];
						}

						if (empty($savedServer)){
							// Если данных нет, то проще всего - просто вводим новые данные сервера без модов и плагинов
							$newServer['ServerCore']['initialised'] = 0;
							$newServer['ServerCore']['payedTill'] = $newPayedTill;
							$newServer['ServerCore']['map'] = $newGameTemplate['GameTemplate']['defaultMap'];
							$newServer['ServerCore']['port'] = $newPort;
							$newServer['Mod']['id']    = '';
							$newServer['Plugin']['id'] = '';
							$newServer['Type']['id'] = $newGameTemplate['Type'][0]['id'];
							$newServer['GameTemplate']['id'] = $newGameTemplate['GameTemplate']['id'];

							if($this->ServerCore->save($newServer)){
								// Сохранить дату использования услуги
								$this->UsedService->save($serviceUsed);
								$this->Session->setflash("Смена игры проведена успешно. В течение несокольких минут будет проведена инициализация нового сервера, ожидайте.", 'flash_success');
							}
							else
							{
								$this->Session->setflash("Возникла ошибка при создании данных нового сервера: ".mysql_error(), 'flash_error');
							}
						}
						else
						{
							// Если же данные есть, переводим их обратно
							$modsList = array();
							$pluginsList = array();

							$savedServer['ServerStore']['port'] = $newServer['ServerCore']['port'];
							$savedServer['ServerStore']['payedTill'] = $newPayedTill;
							$newServer['ServerCore'] = $savedServer['ServerStore'];
							unset($newServer['ServerCore']['orig_id']);
							unset($newServer['ServerCore']['id']);

							$newServer['Type']['id'] = $newGameTemplate['Type'][0]['id'];
							$newServer['GameTemplate']['id'] = $newGameTemplate['GameTemplate']['id'];

							// Список модов
							if (!empty($savedServer['Mod'])){
								foreach ( $savedServer['Mod'] as $mod ) {
						       		$modsList['Mod'][] = $mod['id'];
								}
								$newServer['Mod']    = $modsList;
							}
							else
							{
								$newServer['Mod']['id']    = '';
							}

							// Список плагинов
							if (!empty($savedServer['Plugin'])){
								foreach ( $savedServer['Plugin'] as $plugin ) {
						       		$pluginsList['Plugin'][] = $plugin['id'];
								}
								$newServer['Plugin'] = $pluginsList;
							}
							else
							{
								$newServer['Plugin']['id']    = '';
							}

							if($this->ServerCore->save($newServer)){
								// Сохранить дату использования услуги
								$this->UsedService->save($serviceUsed);
								// удалить из временной базы старые данные
								if($this->ServerStore->delete($savedServer['ServerStore']['id'])){
									$this->Session->setflash("Смена игры проведена успешно. Настройки восстановлены.", 'flash_success');
								}
								else
								{
									$this->Session->setflash("Смена игры проведена успешно. Настройки восстановлены. Но не удалось произвести очистку, свяжитесь с техподдержкой.", 'flash_success');
								}
							}
							else
							{
								$this->Session->setflash("Возникла ошибка при создании данных нового сервера: ".mysql_error(), 'flash_error');
							}

						}
					}
					else
					{
						$this->Session->setflash("Возникла ошибка при резервировании данных сервера: ".mysql_error(), 'flash_error');
					}
				}
				else
				{
					$this->Session->setflash("Не удалось выключить сервер.", 'flash_error');
				}

			}
			else
			{
				$this->Session->setflash("Смена игры невозможна.", 'flash_error');
			}
		}
		//pr($newServer);
		$this->redirect($this->referer());
	}

	function control() {
		$this->DarkAuth->requiresAuth(array('Admin','GameAdmin'));

		$conditions=array();
		// Загрузим необходимые данные для меню выбора
		// Локация //
		$this->loadModel('Location');
		$this->loadModel('ServerClean');
		$locationsList = $this->Location->find('list');
		$locationsList['all'] = 'Все локации';
		$this->set('locationsList', $locationsList);
		$this->set('locationId', 'all');
		// Конец локации //

		$this->set('statusChoise', 'all');
		// Конец статусов //

		if (!empty($this->data) or $this->Session->check('serversChoise')){
			// Если есть нулевые значения формы, то удалить
			// соотв данные из сессии

			if ( @$this->data['Location']['id'] == 'all' && $this->Session->check('serversChoise.location')){
				$this->Session->delete('serversChoise.location');
			}

			if ( @$this->data['Server']['status'] == 'all' && $this->Session->check('serversChoise.status')){
				$this->Session->delete('serversChoise.status');
			}

			if ( @$this->data['User']['username'] == 'all' && $this->Session->check('serversChoise.username')){
				$this->Session->delete('serversChoise.username');
				unset($this->data['User']['username']);
			}

			if ( @$this->data['Server']['id'] == 'all' && $this->Session->check('serversChoise.serverId')){
				$this->Session->delete('serversChoise.serverId');
				unset($this->data['Server']['id']);
			}

			if ( (@$this->data['Server']['address'] == 'all') && $this->Session->check('serversChoise.serverIp')){
				$this->Session->delete('serversChoise.serverIp');
				unset($this->data['Server']['address']);
			}


			// Если есть и в сессии, и форма, то брать данные из формы
			if($this->Session->check('serversChoise')){
				// Проверка локации
				if ( (empty($this->data['Location']['id'])) && $this->Session->check('serversChoise.location') )
					{
					 	$this->data['Location']['id'] = $this->Session->read('serversChoise.location');
					}
				// Проверка статусов
				if ( (empty($this->data['Server']['status'])) && $this->Session->check('serversChoise.status') )
					{
					 	$this->data['Server']['status'] = $this->Session->read('serversChoise.status');
					}
				// Проверка имени клиента
				if ( (empty($this->data['User']['username'])) && $this->Session->check('serversChoise.username') )
					{
					 	$this->data['User']['username'] = $this->Session->read('serversChoise.username');
					}
				// Проверка ID сервера
				if ( (empty($this->data['Server']['id'])) && $this->Session->check('serversChoise.serverId') )
					{
					 	$this->data['Server']['id'] = $this->Session->read('serversChoise.serverId');
					}

				// Проверка IP сервера
				if ( (empty($this->data['Server']['address'])) && $this->Session->check('serversChoise.serverIp') )
					{
					 	$this->data['Server']['address'] = $this->Session->read('serversChoise.serverIp');
					}

			}

			if(!empty($this->data['Location']['id']) and $this->data['Location']['id'] != 'all'){

				$this->Location->RootServer->unbindModel(array(
										'hasAndBelongsToMany' => array(
															'RootServerIp'
												)),
											false);

				if (!empty($this->data['Server']['status'])){
					$locationStatus = array('status' => $this->data['Server']['status']);
				}
				else
				{
					$locationStatus = '';
				}

				$this->Location->RootServer->bindModel(array(
											'hasAndBelongsToMany' => array(
																'Server' => array('className' => 'Server',
																				  'joinTable' => 'servers_root_servers',
																				  'fields' => 'id',
																				  'conditions' => $locationStatus
																				  )
													)));
				$location = $this->Location->find('all', array( 'recursive' => '2',
																'conditions' => array('id' =>$this->data['Location']['id'])));

				foreach ( $location[0]['RootServer'][0]['Server'] as $server ) {
					$serversIds[] = $server['id'];
				}

				//pr($serversIds);
				$conditions['Server.id'] = @$serversIds;
				$this->Session->write('serversChoise.location', $this->data['Location']['id']);
				$this->set('locationId', @$this->data['Location']['id']);

			}

			/*	Лучше статусы обработать отдельно от локации, если та не задана.
			    Это потому, чтобы в локации иметь возможность сократить
			    объем запрашиваемых данных.
			*/
			if (!empty($this->data['Server']['status']) and $this->data['Server']['status'] != 'all'){

				if (empty($this->data['Location']['id']) or $this->data['Location']['id'] == 'all'){
					$conditions['Server.status'] = $this->data['Server']['status'];
				}

				$this->Session->write('serversChoise.status', $this->data['Server']['status']);
				$this->set('statusChoise', @$this->data['Server']['status']);
			}

			// Выбор серверов по логину клиента
			if (!empty($this->data['User']['username']) and $this->data['User']['username'] != 'all'){

				$this->Server->User->unbindModel(array( 'hasAndBelongsToMany' => array(
																						'Group',
																						'SupportTicket'
																					   )));

				$this->Server->User->bindModel(array(
														'hasAndBelongsToMany' => array(
																			'Server' => array('className' => 'Server',
																							  'fields' => 'id'
																							  )
																)));

				$searchUser = $this->Server->User->findByUsername($this->data['User']['username']);

				if (!empty($searchUser)){
					foreach ( $searchUser['Server'] as $server ) {
						$serversIds[] = $server['id'];
					}

					//pr($serversIds);
					$conditions['Server.id'] = @$serversIds;
					$this->Session->write('serversChoise.username', $this->data['User']['username']);
					$this->set('searchUserName', @$this->data['User']['username']);
				}

			}

			// Выбор сервера по ID
			if (!empty($this->data['Server']['id']) and $this->data['Server']['id'] != 'all'){
				//pr($this->data['Server']['id']);
				$conditions['Server.id'][0] = $this->data['Server']['id'];
				$this->Session->write('serversChoise.serverId', $this->data['Server']['id']);
				$this->set('searchServerId', $this->data['Server']['id']);
			}

			if (!empty($this->data['Server']['address']) and $this->data['Server']['address'] != 'all')
			{

				if (!empty($this->data['Server']['port']) and $this->data['Server']['port'] != 'all')
				{
					$portCondition = array('port' => $this->data['Server']['port']);
				}
				else
				{
					$portCondition = array();
				}

				$searchIp = $this->ServerClean->find('all', array(
																	  'fields' => 'id',
																	  'conditions' => array(
																	  'address LIKE' => $this->data['Server']['address'].'%',
																	   $portCondition
																	  )
																	  ));

				if (!empty($searchIp)){
					foreach ( $searchIp as $server ) {
						$serversIds[] = $server['ServerClean']['id'];
					}
				}

				$conditions['Server.id'] = @$serversIds;

				$this->Session->write('serversChoise.serverIp', $this->data['Server']['address']);
				$this->set('searchServerIp', @$this->data['Server']['address']);
			}

			if (!empty($this->data['Server']['port']) and $this->data['Server']['port'] != 'all')
				{
					if (empty($this->data['Server']['address']) or $this->data['Server']['address'] == 'all')
					{
						$searchPort = $this->ServerClean->find('all', array(
																		  'fields' => 'id',
																		  'conditions' => array (
																		  'port' => $this->data['Server']['port'])
																		  ));
					}

				if (!empty($searchPort)){
					foreach ( $searchPort as $server ) {
						$serversIds[] = $server['ServerClean']['id'];
					}
				}

				$conditions['Server.id'] = @$serversIds;

				$this->Session->write('serversChoise.serverPort', $this->data['Server']['port']);
				$this->set('searchServerPort', @$this->data['Server']['port']);
			}
		}

		// Нефиг запрашивать лишнюю информацию из базы
		$this->Server->unbindModel(array(
										'hasAndBelongsToMany' => array(
															'Order',
															'Plugin',
															'Service',
															'VoiceMumbleParam',
															'RadioShoutcastParam'
												)),
											false);

		if (!empty($conditions)){
			$this->paginate =  array(   'conditions'=>$conditions,
										'order' => array(
												            'Server.id' => 'desc'
												        ));
			}
		else
		{
			$this->paginate =  array(   'order' => array(
												            'Server.id' => 'desc'
												        ));
		}

		$this->data = $this->paginate('Server');
		//pr($this->data);
		$serversIds = ''; // Обнулить список ID серверов, для которых будем запрашивать статус
		foreach ($this->data as $key=>$server):

			if (time() < strtotime($server['Server']['payedTill'])
					&&
				$server['Server']['initialised'] == 1 ){
				// В список серверов, у которых запросим статус, внести только оплаченные
				// и инициализированные, чтоб не гонять лишние запросы
				$serversIds .= ':'.strval($server['Server']['id']);

			// Рассчет графикаокончания аренды
			$this->data[$key]['Server']['scaleTime'] = $this->scaleDate($server['Server']['payedTill']);
			$this->data[$key]['Server']['name'] = strip_tags($server['Server']['name']); //XSS
			}
		endforeach;

		$this->set('servers',$this->data);
		$this->set('serversIds',$serversIds);

		$this->loadModel('Support');
		$openTickets = $this->Support->query("SELECT COUNT(*) FROM `support_tickets` WHERE `status`='open'");
		$this->set('openTickets', $openTickets[0][0]['COUNT(*)']);
	}

	function delete($id) {
		$this->DarkAuth->requiresAuth(array('Admin'));
		$this->loadModel('RootServer');
		$this->Server->id = $id;
		$server = $this->Server->read();
		// Также надо освободить слоты на физическом сервере
		$this->RootServer->id = $server['RootServer'][0]['id'];
		$rootServer = $this->RootServer->read();
		$slotsBought = $rootServer['RootServer']['slotsBought'] - $server['Server']['slots'];

		if ( $slotsBought < 0 && $rootServer['RootServer']['slotsBought'] != 0 ){
			$slotsBought = 0;
		}
		if (	$rootServer['RootServer']['slotsBought'] !=0 // Купленых слотов больше, чем удаляем
				&&
				$server['Server']['initialised'] == 1 // Сервер инициализирован
				&&
				$server['Type'][0]['id'] == 1 // Тип сервера - игровой
			){
			// Незачем делать лишний запрос к базе, а также
			// слоты удалать только от инициализированных серв-в
			$this->RootServer->saveField('slotsBought',$slotsBought);
		}


		// Удаляем сервер из базы
		/* Пометить сервер на удаление, чтоб далее скрипт в cron
		 * удалял и файлы, и из базы
		*/
		$this->Server->id = $id;
		$tmp = $this->Server->read();
		if (!empty($tmp['RootServer'][0]['id'])){ // Помечать на удаление, только еслиесть привязка к rootserver
			$server['Server'] = $tmp['Server'];
			// Оставить только связи, необходимые скрипту очистки.
			$server['User']['id'] = $tmp['User'][0]['id'];
			$server['GameTemplate']['id'] = $tmp['GameTemplate'][0]['id'];
			$server['RootServer']['id'] = $tmp['RootServer'][0]['id'];
			$server['Type']['id'] = $tmp['Type'][0]['id'];

			$server['Server']['action'] = 'delete';

			if ($this->Server->saveAll($server)){
					$this->Session->setflash("Сервер помечен для удаления, которое будет проведено по расписанию на физическом сервере.", 'flash_success');
			}
			else
			{
				$this->Session->setflash("Возникла ошибка при изменении данных сервера: ".mysql_error(), 'flash_error');
			}
		}
		else
		{

			if ($this->Server->delete($id)){
					$this->Session->setflash("Информация о сервере удалена.", 'flash_success');
			}
			else
			{
				$this->Session->setflash("Возникла ошибка при удалении данных сервера: ".mysql_error(), 'flash_error');
			}
		}
		$this->redirect($this->referer());
	}

	/* Переинициализация сервера
	 *  - Снять ключ инициализации в базе
	 *  - Снять все связи с модами и плагинами
	 */
	function reInit ($id = null){
		$this->DarkAuth->requiresAuth();
		$this->loadModel('ServerModPlugin');
		if ($this->checkRights($id)){
			$this->ServerModPlugin->bindModel(array(
											'hasAndBelongsToMany' => array(
																'GameTemplate' => array(),
																'User' => array()
													)));
			$this->ServerModPlugin->id = $id;
			$server = $this->ServerModPlugin->read();
			$server['ServerModPlugin']['initialised'] = 0;
			$server['ServerModPlugin']['map'] = $server['GameTemplate'][0]['defaultMap'];
			$server['Mod']['id'] = "";
			$server['Plugin']['id'] = "";
			$server['ServerModPlugin']['status'] = "";
			$server['ServerModPlugin']['statusDescription'] = "";
			$userId = $server['User'][0]['id'];
//			pr($server);
			if ($this->ServerModPlugin->saveAll($server)){
				$this->Session->setflash("Сервер готов к переинициализации, которая будет проведена в течение нескольких минут.", 'flash_success');
				$this->TeamServer->logAction('Сброс настроек сервера '.strtoupper(@$server['GameTemplate'][0]['name']).' #'.$id, 'ok', $userId);
			}
			else
			{
				$this->Session->setflash("Возникла ошибка при изменении данных сервера. Обратитесь в техподдержку.", 'flash_error');
				$this->TeamServer->logAction('Неудачный сброс настроек сервера '.strtoupper(@$server['GameTemplate'][0]['name']).' #'.$id, 'error', $userId);
			}

		}
		$this->redirect(array('action'=>'index'));

	}

	/* Функция для переключения параметров - 0/1
	 * Чтобы нельзя было прописать в любое поле таблицы
	 * левые значения, жестко прописываю тут вероятные поля.
	 */
	function switchParam ($param = null, $case = null, $id = null){
		$this->DarkAuth->requiresAuth();
		if (is_null($id)){
			$id = @$this->data['Server']['id'];
		}
		if ($this->checkRights($id)){
			$this->Server->id = $id;

			switch ( $param ) {
				case 'vac':
					$messageOff = 'VAC отключен успешно.';
					$messageOn  = 'VAC включен успешно.';
					$dbField = 'vac';
					break;
				case 'nomaster':
					$messageOff = 'Мастер серверы отключены успешно.';
					$messageOn  = 'Мастер серверы включены успешно.';
					$dbField = 'nomaster';
					break;
				case 'punkbuster':
					$messageOff = 'Punkbuster отключен успешно.';
					$messageOn  = 'Punkbuster включен успешно.';
					$dbField = 'punkbuster';
					break;
				case 'autoupdate':
					$messageOff = 'Обновление сервера отключено успешно.';
					$messageOn  = 'Обновление сервера включено.';
					$dbField = 'autoUpdate';
					break;

				default:
					$this->Session->setflash("Неверный параметр.", 'flash_error');
					$this->redirect(array('action'=>'editStartParams', $id));
					break;
			}

			if ($case == 'off'){
				$switchTo = 0;
				$this->Session->setflash($messageOff." Перезапустите сервер для применения изменений.", 'flash_success');
			}
			else if ($case == 'on')
			{
				$switchTo = 1;
				$this->Session->setflash($messageOn." Перезапустите сервер для применения изменений.", 'flash_success');
			}

			if (!$this->Server->saveField($dbField, $switchTo)){
				$this->Session->setflash("Возникла ошибка при изменении данных сервера. Обратитесь в техподдержку. Ошибка: ".mysql_error(), 'flash_error');
			}

		}
		$this->redirect(array('action'=>'editStartParams', $id));

	}

	// Включение-отключение привязки к EAC
	function switchEac ($id = null){
		$this->DarkAuth->requiresAuth();

		//Проверка прав на сервер
		if ($this->checkRights($id)){
			$this->loadModel('Eac');

			$eacServer = $this->Eac->findByServerId($id);

			if (!empty($eacServer))
			{
				$this->Eac->id = $eacServer['Eac']['id'];

				if ($eacServer['Eac']['active'] == 1)
				{
					if ($this->Eac->saveField('active', 0))
					{
						$this->Session->setflash("Сервер EAC отключен.", 'flash_success');
					}
					else
					{
						$this->Session->setflash("Произошла ошибка при отключении сервера EAC: ".mysql_error(), 'flash_error');
					}
				}
				else
				{
					if ($this->Eac->saveField('active', 1))
					{
						$this->Session->setflash("Сервер EAC включен.", 'flash_success');
					}
					else
					{
						$this->Session->setflash("Произошла ошибка при включении сервера EAC: ".mysql_error(), 'flash_error');
					}
				}
			}
			else
			{
				$this->Session->setflash("Нет привязанного игрового сервера.", 'flash_error');
			}

		}

		//$this->redirect('result');
		$this->render('result');
	}

	/*
	 * Данная функция переадресует на просмотр параметров
	 * сервера определенного типа
	 */
	function viewServer ($id = null){
		$this->DarkAuth->requiresAuth();
		$this->loadModel('GameTemplate');
		$this->Server->id = $id;
		$server = $this->Server->read();
		// В зависимости от типа и шаблона, переадресуем на соответвующий контроллер
		// Да, можно это делать из функции, что привязана к типу, но
		// тогда будет сгененрирован дополнительный редирект 304,
		// что не есть хорошо.
		$serverType = strtolower($server['Type'][0]['name']);

		switch ( $serverType ) {
			case 'voice':
			case 'radio':
				$redirTo = 'view'.
							ucfirst($serverType).
							ucfirst(strtolower($server['GameTemplate'][0]['name']));
				// В результате будет сгенерирован редирект вроде
				// viewVoiceMumble

				break;


			default:
				$redirTo = 'view'.ucfirst($serverType); // Сделать первый символ в верхнем регистре
				break;

		}

		$this->redirect(array('action'=>$redirTo,$id));



	}

	function viewVoiceMumble ($id = null){
		$this->DarkAuth->requiresAuth();
		//Проверка прав на сервер
		if ($this->checkRights($id)){
			$this->Server->id = $id;
			$server = $this->Server->read();

			$this->set('serverId',$id);

			$serverIp = $server['Server']['address'];
			$serverPort = $server['Server']['port'];
	 		$serverId = $server['Server']['id'];
	 		$serverName = $server['GameTemplate'][0]['name'];
	 		$serverType = $server['Type'][0]['name'];

			$fp = @fsockopen($serverIp, 80, $errno, $errstr, 10);
				if (!$fp) {

				    $this->Session->setFlash("Невозможно подключиться к серверу: <br />\n"."$errstr ($errno)<br />\n", 'flash_error');

				} else

				{
					$data = "action=check".
							"&ip=".$serverIp.
							"&port=".$serverPort;
					$out = "POST /~configurator/scripts/check_port.py?".$data." HTTP/1.1\r\n";
				    $out .= "Host: ".$serverIp."\r\n";
					$out .= "Connection: Close\r\n\r\n";

				    fwrite($fp, $out);

				    $response = "";

				    while (!feof($fp)) {
				        $response.=fgets($fp, 1024);
				    }
				    fclose($fp);


					$response=split("\r\n\r\n",$response);
				    $header=$response[0];
				    $responsecontent=$response[1];
				    if(!(strpos($header,"Transfer-Encoding: chunked")===false)){
				        $aux=split("\r\n",$responsecontent);
				        for($i=0;$i<count($aux);$i++)
				            if($i==0 || ($i%2==0))
				                $aux[$i]="";
				        $responsecontent=implode("",$aux);
				    }


		 		 		$var = eregi("<!-- RESULT START -->(.*)<!-- RESULT END -->", $responsecontent, $out);
		 		 		$responsecontent = trim($out[1]);

			 		 	if ($responsecontent == "open"){
			 		 		$this->set('status','stoped');
			 		 	}
			 		 	else if ($responsecontent == "used"){
			 		 		$this->set('status','runing');
						}
						else
						{
							pr($responsecontent); // DEBUG!!!
						}

				}
			}
	}

	function viewRadioShoutcast($id = null){
		$this->DarkAuth->requiresAuth();
		if ($this->checkRights($id)){
			$this->Server->id = $id;
			$server = $this->Server->read();
			$this->set('serverId',$id);

			$radioIp   = $server['Server']['address'];
			$radioPort = $server['Server']['port'];

			$dataset = $this->TeamServer->webGet($radioIp, $radioPort, "7.html");

			if($dataset == "") {
			  		$this->set('status','stoped');
			}
			else
			{
				  $entries = explode(",",$dataset);
				  $this->set('status','runing');

				  $stats = array('listeners' => $entries[0],
				  				'listeneresPeak' => $entries[2],
				  				'maxListeners'   => $entries[3],
				  				'totalListeners' => $entries[4],
				  				);
				  if ( $entries[1] == 0 ){
				  		$stats['status'] = 'Оффлайн';
				  }
				  else
				  {
				  		$stats['status'] = 'Онлайн';
				  }

				  if ( $entries[5] == 0 ){
				  		$stats['bitrate'] = 'н/д';
				  }
				  else
				  {
				  		$stats['bitrate'] = $entries[5];
				  }

				  if ( $entries[6] == ''){
				  		$stats['songTitle'] = 'н/д';
				  }
				  else
				  {
				  		$stats['songTitle'] = $entries[6];
				  }


				  $this->set('stats',$stats);
				  $this->set('maxBitrate',$server['RadioShoutcastParam'][0]['bitrate']);
			}



		}

	}
		/*
	 * Данная функция переадресует на просмотр параметров
	 * сервера определенного типа
	 */
	function viewLog ($id = null, $param = null){
		$this->DarkAuth->requiresAuth();
		$this->loadModel('GameTemplate');
		$this->Server->id = $id;
		$server = $this->Server->read();
		// В зависимости от типа и шаблона, переадресуем на соответвующий контроллер
		// Да, можно это делать из функции, что привязана к типу, но
		// тогда будет сгененрирован дополнительный редирект 304,
		// что не есть хорошо.
		switch ( strtolower($server['Type'][0]['name']) ) {
			case 'radio':
			case 'voice':
				$redirTo = 'viewLog'.
							ucfirst(strtolower($server['Type'][0]['name'])).
							ucfirst(strtolower($server['GameTemplate'][0]['name']));
				// В результате будет сгенерирован редирект вроде
				// viewLogVoiceMumble

				break;

			default:
				$redirTo = 'viewLog'.ucfirst(strtolower($server['Type'][0]['name'])); // Сделать первый символ в верхнем регистре
				break;

		}

		$this->redirect(array('action'=>$redirTo,$id,$param));



	}
	function viewLogSrcds($id = null, $type = null){
		/* Тут мы получаем список логов для определённого сервера SRCDS
		 * $id - ID сервера
		 * $type - тип логов:
		 * 		run - логи запущенного сервера
		 * 		start - логи запуска сервера
		 * 		update - логи обновления сервера
		*/
		$this->DarkAuth->requiresAuth();
		//Проверка прав на сервер
		if ($this->checkRights($id)){
			$this->Server->id = $id;
			$server = $this->Server->read();

			$ip = $server['Server']['address'];
			$userId = $server['User'][0]['id'];
			$serverTemplate = $server['GameTemplate'][0]['name'];
			$serverDebugLogPath = $server['GameTemplate'][0]['rootPath'];
			if ($type == null){
				$type = "run";
			}

			if ($type == 'debug'){
				$data = "action=list" .
						"&id=".$id .
						"&pattern=screenlog.[0-9]" .
						"&lines=0" .
						"&logpath=servers/".$serverTemplate."_".$id."/".$serverDebugLogPath .
						"&logname=null";
			}
			else
			{
				$data = "action=list" .
						"&id=".$id .
						"&pattern=*.log" .
						"&lines=0" .
						"&logpath=logs/".$serverTemplate."_".$id."/".$type .
						"&logname=null";
			}

			$request = "~configurator/scripts/subscript_read_log.py?".$data;

			$response = $this->TeamServer->webGet($ip, 0, $request);

			if ($response !== false){

				$var = eregi("<!-- LIST START -->(.*)<!-- LIST END -->", $response, $out);
		 		$response = trim($out[1]);
				$list = array_reverse(explode(";", $response)); // Получить массив из ответа сервера
				array_shift($list); // Удалить пустой элемент, т.к. изначально получаем строку с ";" в конце
				$list = array_map('trim',$list); // Удалить пробелы и переносы строки
				//rsort($list); // Обратная сортировка по элементам
				$list = array_slice($list,0,15);// Обрезка массива до 15 элементов

				$this->set('logList',$list);
				$this->set('id', $id);
				$this->set('type', $type);
			}
			else
			{

				$this->Session->setFlash('Не удалось получить список логов. Сервер недоступен. Попробуйте позже.<br/>' .
									     'Если ошибка не исчезнет, обратитесь в службу поддержки.', 'flash_error');

			}

		}

	}

	function viewLogHlds($id = null, $type = null){
		/* Тут мы получаем список логов для определённого сервера SRCDS
		 * $id - ID сервера
		 * $type - тип логов:
		 * 		run - логи запущенного сервера
		 * 		start - логи запуска сервера
		 * 		update - логи обновления сервера
		*/
		$this->DarkAuth->requiresAuth();
		//Проверка прав на сервер
		if ($this->checkRights($id)){
			$this->Server->id = $id;
			$server = $this->Server->read();

			$ip = $server['Server']['address'];
			$userId = $server['User'][0]['id'];
			$serverTemplate = $server['GameTemplate'][0]['name'];
			$serverRunLogPath = $server['GameTemplate'][0]['addonsPath'];
			$serverDebugLogPath = $server['GameTemplate'][0]['rootPath'];

			if ($type == null or $type == "run"){
				$type = "run";
				$data = "action=list" .
						"&id=".$id .
						"&pattern=*.log" .
						"&lines=0" .
						"&logpath=servers/".$serverTemplate."_".$id."/".$serverRunLogPath."/logs" .
						"&logname=null";
			}
			else
			if ($type == 'debug'){
				$data = "action=list" .
						"&id=".$id .
						"&pattern=screenlog.[0-9]" .
						"&lines=0" .
						"&logpath=servers/".$serverTemplate."_".$id."/".$serverDebugLogPath .
						"&logname=null";
			}
			else
			{
				$data = "action=list" .
						"&id=".$id .
						"&pattern=*.log" .
						"&lines=0" .
						"&logpath=logs/".$serverTemplate."_".$id."/".$type .
						"&logname=null";
			}

			$request = "~configurator/scripts/subscript_read_log.py?".$data;

			$response = $this->TeamServer->webGet($ip, 0, $request);
			if ($response !== false){

				$var = eregi("<!-- LIST START -->(.*)<!-- LIST END -->", $response, $out);
		 		$response = trim($out[1]);
				$list = array_reverse(explode(";", $response)); // Получить массив из ответа сервера
				array_shift($list); // Удалить пустой элемент, т.к. изначально получаем строку с ";" в конце
				$list = array_map('trim',$list); // Удалить пробелы и переносы строки
				rsort($list); // Обратная сортировка по элементам
				$list = array_slice($list,0,15);// Обрезка массива до 15 элементов
				$this->set('logList',$list);
				$this->set('id', $id);
				$this->set('type', $type);

			}
			else
			{

				$this->Session->setFlash('Не удалось получить список логов. Сервер недоступен. Попробуйте позже.<br/>' .
									     'Если ошибка не исчезнет, обратитесь в службу поддержки.', 'flash_error');

			}

		}

	}

	function viewLogCod ($id = null, $type = null){
		/* Тут мы получаем список логов для определённого сервера COD
		 * $id - ID сервера
		 * $type - тип логов:
		 * 		game - игровые логи сервера
		 * 		console - логи консоли сервера
		 * 		pb - логи PunkBuster
		*/
		$this->DarkAuth->requiresAuth();
		//Проверка прав на сервер
		if ($this->checkRights($id)){
			$this->Server->id = $id;
			$server = $this->Server->read();

			$ip = $server['Server']['address'];
			$userId = $server['User'][0]['id'];
			$serverTemplate = $server['GameTemplate'][0]['name'];
			$serverDebugLogPath = $server['GameTemplate'][0]['rootPath'];

			if ($type == null){
				$type = "game";
			}

			switch ( $serverTemplate ) {
				case 'cod2':
					$commonDir = '.callofduty2';
					// Мод по-умолчанию
					if (empty($server['Server']['mod'])){
						$serverMod = 'main';
					}
					else
					{
						$serverMod = 'mods/'.$server['Server']['mod'];
					}
					break;

				case 'cod4':
				case 'cod4v1':
				case 'cod4fixed':
					// Директория, в которую пишутся служебные файлы всех серверов COD4
					$commonDir = '.callofduty4';

					// Мод по-умолчанию
					if (empty($server['Server']['mod'])){
						$serverMod = 'main';
					}
					else
					{
						$serverMod = 'mods/'.strtolower($server['Server']['mod']);
					}
					break;

				default:
					$commonDir = 'logs';
					$serverMod = 'mods/modwarfare';
					break;
			}
			switch ( $type ) {
				case 'game':
					$logPath = $serverMod."/".$serverTemplate."_".$id;
					break;
				case 'console':
					$logPath = $serverMod;
					break;
				case 'pb':
					$logPath = "pb/svlogs";
					break;

				default:
					$logPath = "logs/run";
					break;
			}

			if ($type == 'debug'){
				$data = "action=list" .
						"&id=".$id .
						"&pattern=screenlog.[0-9]" .
						"&lines=0" .
						"&logpath=servers/".$serverTemplate."_".$id."/".$serverDebugLogPath .
						"&logname=null";

			}
			else
			{
				$data = "action=list" .
				        "&id=".$id .
						"&pattern=*.log" .
						"&lines=0" .
						"&logpath=".$commonDir."/".$logPath.
						"&logname=null";
			}

			$request = "~configurator/scripts/subscript_read_log.py?".$data;

			$response = $this->TeamServer->webGet($ip, 0, $request);

			if ($response !== false){

				$var = eregi("<!-- LIST START -->(.*)<!-- LIST END -->", $response, $out);
		 		$response = trim($out[1]);
				$list = array_reverse(explode(";", $response)); // Получить массив из ответа сервера
				array_shift($list); // Удалить пустой элемент, т.к. изначально получаем строку с ";" в конце
				$list = array_map('trim',$list); // Удалить пробелы и переносы строки
				rsort($list); // Обратная сортировка по элементам
				$list = array_slice($list,0,15);// Обрезка массива до 15 элементов
				$this->set('logList',$list);
				$this->set('id', $id);
				$this->set('type', $type);
				$this->set('logPath', $logPath);

			}
			else
			{

				$this->Session->setFlash('Не удалось получить список логов. Сервер недоступен. Попробуйте позже.<br/>' .
									     'Если ошибка не исчезнет, обратитесь в службу поддержки.', 'flash_error');

			}

		}

	}

	function viewLogUeds ($id = null, $type = null){
		/* Тут мы получаем список логов для определённого сервера COD
		 * $id - ID сервера
		 * $type - тип логов:
		 * 		game - игровые логи сервера
		 * 		debug - логи режима отладки
		*/

		$this->DarkAuth->requiresAuth();
		//Проверка прав на сервер
		if ($this->checkRights($id)){
			$this->Server->id = $id;
			$server = $this->Server->read();

			$ip = $server['Server']['address'];
			$userId = $server['User'][0]['id'];
			$serverTemplate = $server['GameTemplate'][0]['name'];
			$serverDebugLogPath = $server['GameTemplate'][0]['rootPath'];

			if ($type == null){
				$type = "run";
			}

			switch ( $serverTemplate ) {
				case 'killingfloor':
				    // Директория, в которую пишутся служебные файлы всех серверов Killing Floor
					$commonDir = '.killingfloor';
					break;


				default:
					break;
			}
			switch ( $type ) {
				case 'run':
					$logPath = "/System/log/".$serverTemplate."_".$id;
					break;
				case 'update':
					$logPath = 'logs/'.$serverTemplate."_".$id.'/update';
					break;

				default:
					$logPath = "logs/run";
					break;
			}

			if ($type == 'debug'){
				$data = "action=list" .
						"&id=".$id .
						"&pattern=screenlog.[0-9]" .
						"&lines=0" .
						"&logpath=servers/".$serverTemplate."_".$id."/".$serverDebugLogPath .
						"&logname=null";

			}
			if ($type == 'update'){
				$data = "action=list" .
						"&id=".$id .
						"&pattern=*.log" .
						"&lines=0" .
						"&logpath=".$logPath .
						"&logname=null";

			}
			else
			{
				$data = "action=list" .
				        "&id=".$id .
						"&pattern=*.log" .
						"&lines=0" .
						"&logpath=".$commonDir."/".$logPath.
						"&logname=null";
			}

			$request = "~configurator/scripts/subscript_read_log.py?".$data;

			$response = $this->TeamServer->webGet($ip, 0, $request);

			if ($response !== false){

				$var = eregi("<!-- LIST START -->(.*)<!-- LIST END -->", $response, $out);
		 		$response = trim($out[1]);
				$list = array_reverse(explode(";", $response)); // Получить массив из ответа сервера
				array_shift($list); // Удалить пустой элемент, т.к. изначально получаем строку с ";" в конце
				$list = array_map('trim',$list); // Удалить пробелы и переносы строки
				rsort($list); // Обратная сортировка по элементам
				$list = array_slice($list,0,15);// Обрезка массива до 15 элементов
				$this->set('logList',$list);
				$this->set('id', $id);
				$this->set('type', $type);
				$this->set('logPath', $logPath);

			}
			else
			{

				$this->Session->setFlash('Не удалось получить список логов. Сервер недоступен. Попробуйте позже.<br/>' .
									     'Если ошибка не исчезнет, обратитесь в службу поддержки.', 'flash_error');

			}

		}

	}

	function printLogSrcds($id = null, $logName = null, $type = null){
		// Тут читаем лог для определённого сервера SRCDS
		$this->DarkAuth->requiresAuth();
		//Проверка прав на сервер
		if ($this->checkRights($id)){
			$this->Server->id = $id;
			$server = $this->Server->read();

			$ip = $server['Server']['address'];
			$userId = $server['User'][0]['id'];
			$serverTemplate = $server['GameTemplate'][0]['name'];
			$serverDebugLogPath = $server['GameTemplate'][0]['rootPath'];

			if ($type == 'debug'){
				$data = "action=read" .
						"&id=".$id .
						"&pattern=null" .
						"&lines=1000" .
						"&logpath=servers/".$serverTemplate."_".$id."/".$serverDebugLogPath .
						"&logname=".$logName;
			}
			else
			{
				$data = "action=read" .
						"&id=".$id .
						"&pattern=null" .
						"&lines=1000" .
						"&logpath=logs/".$serverTemplate."_".$id."/".$type .
						"&logname=".$logName;
			}


			$request = "~configurator/scripts/subscript_read_log.py?".$data;

			$response = $this->TeamServer->webGet($ip, 0, $request);

			if ($response !== false){

				$var = eregi("<!-- LOG START -->(.*)<!-- LOG END -->", $response, $out);
		 		$response = trim($out[1]);
				$strings = preg_split("/(\n|\r|\f)/", $response);
				$strings = array_reverse($strings);
				$log = '';
				foreach ( $strings as $string ) {
       				$log .= $string."\n";
				}
				$this->set('log',$log);
				$this->set('logName',$logName);

			}
			else
			{

				$this->Session->setFlash('Не удалось прочесть лог. Сервер недоступен. Попробуйте позже.<br/>' .
									     'Если ошибка не исчезнет, обратитесь в службу поддержки.', 'flash_error');

			}

		}
		$this->render("print_log");

	}

	function printLogHlds($id = null, $logName = null, $type = null){
		// Тут читаем лог для определённого сервера SRCDS
		$this->DarkAuth->requiresAuth();
		//Проверка прав на сервер
		if ($this->checkRights($id)){
			$this->Server->id = $id;
			$server = $this->Server->read();

			$ip = $server['Server']['address'];
			$userId = $server['User'][0]['id'];
			$serverTemplate = $server['GameTemplate'][0]['name'];
			$serverRunLogPath = $server['GameTemplate'][0]['addonsPath'];
			$serverDebugLogPath = $server['GameTemplate'][0]['rootPath'];

			if ($type == 'run'){
				$data = "action=read" .
						"&id=".$id .
						"&pattern=null" .
						"&lines=1000" .
						"&logpath=servers/".$serverTemplate."_".$id."/".$serverRunLogPath."/logs" .
						"&logname=".$logName;
			}
			else
			if ($type == 'debug'){
				$data = "action=read" .
						"&id=".$id .
						"&pattern=null" .
						"&lines=1000" .
						"&logpath=servers/".$serverTemplate."_".$id."/".$serverDebugLogPath .
						"&logname=".$logName;
			}
			else
			{
				$data = "action=read" .
						"&id=".$id .
						"&pattern=null" .
						"&lines=1000" .
						"&logpath=logs/".$serverTemplate."_".$id."/".$type .
						"&logname=".$logName;
			}

			$request = "~configurator/scripts/subscript_read_log.py?".$data;

			$response = $this->TeamServer->webGet($ip, 0, $request);

			if ($response !== false){

				$var = eregi("<!-- LOG START -->(.*)<!-- LOG END -->", $response, $out);
		 		$response = trim($out[1]);

				$strings = preg_split("/(\n|\r|\f)/", $response);
				$strings = array_reverse($strings);
				$log = '';
				foreach ( $strings as $string ) {
       				$log .= $string."\n";
				}
				$this->set('log',$log);
				$this->set('logName',$logName);

			}
			else
			{

				$this->Session->setFlash('Не удалось прочесть лог. Сервер недоступен. Попробуйте позже.<br/>' .
									     'Если ошибка не исчезнет, обратитесь в службу поддержки.', 'flash_error');

			}

		}
		$this->render("print_log");

	}

	function printLogCod($id = null, $logName = null, $logPath = null, $type = null){
		// Тут читаем лог для определённого сервера COD
		$this->DarkAuth->requiresAuth();

		//Проверка прав на сервер
		if ($this->checkRights($id)){
			$this->Server->id = $id;
			$server = $this->Server->read();

			$ip = $server['Server']['address'];
			$userId = $server['User'][0]['id'];
			$serverTemplate = $server['GameTemplate'][0]['name'];
			$serverDebugLogPath = $server['GameTemplate'][0]['rootPath'];

			// Сначала преобразовать путь в корректный,
			// потом убрать все возможные обратные пути,
			// а то умников полно, навярняка захотят считать что-то
			// вне пути сервера.
			$path = '';
			$pathParts = explode('|',$logPath);
			foreach ( $pathParts as $pathPart ) {
	        	$path .= trim($pathPart,'./\\')."/";
			}

			switch ( $serverTemplate ) {
					case 'cod2':
						// Директория, в которую пишутся служебные файлы всех серверов COD2
						$commonDir = '.callofduty2';
						break;
					case 'cod4':
					case 'cod4fixed':
					case 'cod4v1':
						// Директория, в которую пишутся служебные файлы всех серверов COD4
						$commonDir = '.callofduty4';
						break;
					default:
						break;
			}

			if ($type == 'debug'){
				$data = "action=read" .
						"&id=".$id .
						"&pattern=null" .
						"&lines=1000" .
						"&logpath=servers/".$serverTemplate."_".$id."/".$serverDebugLogPath .
						"&logname=".$logName;
			}
			else
			{
				$data = "action=read" .
						"&id=".$id .
						"&pattern=null" .
						"&lines=700" .
						"&logpath=".$commonDir."/".$path .
						"&logname=".$logName;
			}


			$request = "~configurator/scripts/subscript_read_log.py?".$data;

			$response = $this->TeamServer->webGet($ip, 0, $request);

			if ($response !== false){

				$var = eregi("<!-- LOG START -->(.*)<!-- LOG END -->", $response, $out);
		 		$response = trim($out[1]);

		 		// Для игрового лога надо почистить мусор и перекодировать в юникод
		 		if ($logName == "games_mp.log"){
		 			$response = mb_eregi_replace('^\d{1,3}:\d{1,3}\s-*$', "", $response,'m');
		 		}

				$strings = preg_split("/(\n|\r|\f)/", $response);
				$strings = array_reverse($strings);
				$log = '';
				foreach ( $strings as $string ) {
					if (mb_check_encoding($string, 'CP1251')){
						$log .= mb_convert_encoding($string,'UTF-8', 'CP1251')."\n";
					}
					else
					{
						$log .= $string."\n";
					}
				}
				$this->set('log',$log);
				$this->set('logName',$logName);

			}
			else
			{

				$this->Session->setFlash('Не удалось прочесть лог. Сервер недоступен. Попробуйте позже.<br/>' .
									     'Если ошибка не исчезнет, обратитесь в службу поддержки.', 'flash_error');
			}

		}
		$this->render("print_log");

	}

	function printLogUeds($id = null, $logName = null, $type = null){
		// Тут читаем лог для определённого сервера Ueds
		$this->DarkAuth->requiresAuth();
		//Проверка прав на сервер
		if ($this->checkRights($id)){
			$this->Server->id = $id;
			$server = $this->Server->read();

			$ip = $server['Server']['address'];
			$userId = $server['User'][0]['id'];
			$serverTemplate = $server['GameTemplate'][0]['name'];
			$serverRunLogPath = $server['GameTemplate'][0]['addonsPath'];
			$serverDebugLogPath = $server['GameTemplate'][0]['rootPath'];

			switch ( $serverTemplate ) {
					case 'killingfloor':
						// Директория, в которую пишутся служебные файлы всех серверов killingfloor
						$commonDir = '.killingfloor/System';
						break;

					default:
						break;
			}

			if ($type == 'run'){
				$data = "action=read" .
						"&id=".$id .
						"&pattern=null" .
						"&lines=1000" .
						"&logpath=".$commonDir.'/log/'.$serverTemplate."_".$id.
						"&logname=".$logName;
			}
			else
			if ($type == 'debug'){
				$data = "action=read" .
						"&id=".$id .
						"&pattern=null" .
						"&lines=1000" .
						"&logpath=servers/".$serverTemplate."_".$id."/".$serverDebugLogPath .
						"&logname=".$logName;
			}
			else
			{
				$data = "action=read" .
						"&id=".$id .
						"&pattern=null" .
						"&lines=1000" .
						"&logpath=logs/".$serverTemplate."_".$id."/".$type .
						"&logname=".$logName;
			}

			$request = "~configurator/scripts/subscript_read_log.py?".$data;

			$response = $this->TeamServer->webGet($ip, 0, $request);

			if ($response !== false){

				$var = eregi("<!-- LOG START -->(.*)<!-- LOG END -->", $response, $out);
		 		$response = trim($out[1]);

				$strings = preg_split("/(\n|\r|\f)/", $response);
				$strings = array_reverse($strings);
				$log = '';
				foreach ( $strings as $string ) {
       				$log .= $string."\n";
				}
				$this->set('log',$log);
				$this->set('logName',$logName);

			}
			else
			{

				$this->Session->setFlash('Не удалось прочесть лог. Сервер недоступен. Попробуйте позже.<br/>' .
									     'Если ошибка не исчезнет, обратитесь в службу поддержки.', 'flash_error');

			}

		}
		$this->render("print_log");

	}

	function viewLogVoiceMumble($id = null){
		$this->DarkAuth->requiresAuth();
		//Проверка прав на сервер
		if ($this->checkRights($id)){
			$this->Server->id = $id;
			$server = $this->Server->read();

			$ip = $server['Server']['address'];
			$userId = $server['User'][0]['id'];

			// Обращаемся к файлу, в который пишется весь вывод скрипта обновления

			$data = "action=read" .
					"&id=".$id .
					"&pattern=null" .
					"&lines=500" .
					"&logpath=public_html/output" .
					"&logname=mumble_".$id.".log";

			$request = "~configurator/scripts/subscript_read_log.py?".$data;

			$response = $this->TeamServer->webGet($ip, 0, $request);

			if ($response !== false){

				$var = eregi("<!-- LOG START -->(.*)<!-- LOG END -->", $response, $out);
		 		$response = trim($out[1]);

				$strings = preg_split("/(\n|\r|\f)/", $response);
				$strings = array_reverse($strings);
				$log = '';
				foreach ( $strings as $string ) {
       				$log .= $string."\n";
				}
				$this->set('log',$log);
				$this->set('logName',"mumble_".$id.".log");
			}
			else
			{
				$this->Session->setFlash('Не удалось прочесть лог. Сервер недоступен. Попробуйте позже.<br/>' .
									     'Если ошибка не исчезнет, обратитесь в службу поддержки.', 'flash_error');

			}

		}
		$this->render("print_log");
	}

	function viewLogRadioShoutcast($id = null) {
		$this->DarkAuth->requiresAuth();
		$this->set('id', $id);
	}

	/*
	 * $id - ID сервера
	 * $log - файл лога:
	 * 			main - shoutcast_$id.log
	 * 			w3c  - shotcast_w3c_$id.log
	 */
	function printLogRadioShoutcast( $id = null, $logName = null ){
		$this->DarkAuth->requiresAuth();
		if ( $logName ) {

			//Проверка прав на сервер
			if ($this->checkRights($id)){
				$this->Server->id = $id;
				$this->set('id', $id);
				$server = $this->Server->read();

				$radioIp = $server['Server']['address'];
				$userId = $server['User'][0]['id'];

				// Подготовить запрос для webGet
				if( $logName == 'main'){
					$data = "action=read" .
							"&id=".$id .
							"&pattern=null" .
							"&lines=500" .
							"&logpath=/home/client".$userId."/public_html/output" .
							"&logname=shoutcast_".$id.".log";

					$this->set('logName',"shoutcast_".$id.".log");
				}
				else if( $logName == 'w3c')
				{
					$data = "action=read" .
							"&id=".$id .
							"&pattern=null" .
							"&lines=500" .
							"&logpath=/home/client".$userId."/public_html/output" .
							"&logname=shoutcast_w3c_".$id.".log";

					$this->set('logName',"shoutcast_w3c_".$id.".log");
				}

				$request = "~client".$userId."/common/.read_log.py?".$data;

				$response = $this->TeamServer->webGet($radioIp, 0, $request);

				if ($response !== false){

					$var = eregi("<!-- LOG START -->(.*)<!-- LOG END -->", $response, $out);
			 		$response = trim($out[1]);

					$this->set('log',$response);

				}
				else
				{

					$this->Session->setFlash('Не удалось прочесть лог. Сервер недоступен. Попробуйте позже.<br/>' .
										 'Если ошибка не исчезнет, обратитесь в службу поддержки.', 'flash_error');
				}
			}

		}
		$this->render("print_log");
	}

	function viewSrcds($id = null){
		$this->DarkAuth->requiresAuth();
		$this->loadModel('ServerTemplate');

		if (!empty ($id)) {
			$this->ServerTemplate->id = $id;
			$this->set('serverId', $id);
		}
		$server = $this->ServerTemplate->read();

		$serverStatus['status'] = $server['ServerTemplate']['status'];
		$serverStatus['statusDescription'] = $server['ServerTemplate']['statusDescription'];
		$serverStatus['statusTime'] = $server['ServerTemplate']['statusTime'];
		$serverIp       = $server['ServerTemplate']['address'];
	    $serverport     = $server['ServerTemplate']['port'];
	    $serverTemplate = $server['GameTemplate'][0]['name'];

		$this->set('status', $serverStatus);
	    $this->set('update', $server['ServerTemplate']['autoUpdate']);

	    if (!empty($server['GameTemplate'][0]['current_version']))
	    {
	    	$this->set('currentVersion', $server['GameTemplate'][0]['current_version']);
	    }

	    $info = array();
		if ($server['ServerTemplate']['status'] == 'exec_success'){
			try {
				$handle = new SourceServer(new InetAddress($serverIp), $serverport);
				//$handle->initialize();
				$info['Server']['info']    = $handle->getServerInfo();
				$info['Server']['players'] = $handle->getPlayers();
				//$info['Server']['rules'] = $handle->getRules();
			}
			catch (Exception $e)
			{
				//pr($e);
			}
		}
		//pr($info);
		$this->set('info', $info);
		$this->set('graphs', $this->getStatGraph($id));

		if (!empty($info['Server']))
		{
			$map = $info['Server']['info']['mapName'];
		}
		else
		{
			$map = $server['ServerTemplate']['map'];
		}

		$this->set('mapDesc', $this->mapDesc($server['GameTemplate'][0]['id'], $map));

	}

	/* Вывод состояния HLDS серверов, атакже HLTV
	 * Информацию о сервере собирать библиотечкой steam-condenser,
	 * а о HLTV - gameQ */
	function viewHlds($id = null){
		$this->DarkAuth->requiresAuth();
		$this->loadModel('ServerTemplate');
		$this->layout = 'ajax';

		if (!empty ($id)) {
			$this->ServerTemplate->id = $id;
			$this->set('serverId', $id);
		}
		$server = $this->ServerTemplate->read();

		$serverStatus['status'] = $server['ServerTemplate']['status'];
		$serverStatus['statusDescription'] = $server['ServerTemplate']['statusDescription'];
		$serverStatus['statusTime'] = $server['ServerTemplate']['statusTime'];
		$serverStatus['GameTemplate']['name'] = $server['GameTemplate'][0]['name'];
		$serverStatus['hltvStatus'] = $server['ServerTemplate']['hltvStatus'];
		$serverStatus['hltvStatusDescription'] = $server['ServerTemplate']['hltvStatusDescription'];
		$serverStatus['hltvStatusTime'] = $server['ServerTemplate']['statusTime'];
		$serverIp       = $server['ServerTemplate']['address'];
	    $serverport     = $server['ServerTemplate']['port'];
	    $serverTemplate = $server['GameTemplate'][0]['name'];

		$this->set('status', $serverStatus);
	    $this->set('update', $server['ServerTemplate']['autoUpdate']);

	    if (!empty($server['GameTemplate'][0]['current_version']))
	    {
	    	$this->set('currentVersion', $server['GameTemplate'][0]['current_version']);
	    }

		$info = array();
		if ($server['ServerTemplate']['status'] == 'exec_success'){
			try {
				$handle = new GoldSrcServer(new InetAddress($serverIp), $serverport);
				$handle->initialize();
				$info['Server']['info']    = $handle->getServerInfo();
				$info['Server']['players'] = $handle->getPlayers();
			}
			catch (Exception $e)
			{
				//pr($e);
			}
		}

		if ($server['ServerTemplate']['hltvStatus'] == 'exec_success'){
			App::import('Vendor', 'GameQ', array('file'=>'game_q.php'));
			$handle = new GameQ();

			if ($serverTemplate == 'cs16-old')
			{
				$serverTemplate = 'cs16';
			}

		    $handle->addServer('server#'.$id, array($serverTemplate, $serverIp, $serverport + 1015));
		    $handle->setOption('timeout', 1000);
			$infoTv = $handle->requestData();

		    if ($infoTv['server#'.$id]['gq_online']){

				$infoTv['server#'.$id]['id'] = $id;
				$info['Server']['Hltv'] = $infoTv['server#'.$id];
				//pr( $info['Server']['Hltv']);

		    }
		}
	    $this->set('info', $info);
	    $this->set('graphs', $this->getStatGraph($id));

	    if (!empty($info['Server']))
		{
			$map = $info['Server']['info']['mapName'];
		}
		else
		{
			$map = $server['ServerTemplate']['map'];
		}

	    $this->set('mapDesc', $this->mapDesc($server['GameTemplate'][0]['id'], $map));

	}

	// Просмотр состояния серверов COD
	function viewCod( $id = null ){
		$this->DarkAuth->requiresAuth();
		$this->loadModel('ServerTemplate');
		$this->layout = 'ajax';

		if (!empty ($id)) {
			$this->ServerTemplate->id = $id;
			$this->set('serverId', $id);
		}

		$this->ServerTemplate->bindModel(array(
											'hasAndBelongsToMany' => array(
																'Mod' => array( 'fields' =>'id, name' )
													)));

		$server = $this->ServerTemplate->read();

		$serverStatus['status'] = $server['ServerTemplate']['status'];
		$serverStatus['statusDescription'] = $server['ServerTemplate']['statusDescription'];
		$serverStatus['statusTime'] = $server['ServerTemplate']['statusTime'];
		$serverStatus['GameTemplate']['name'] = $server['GameTemplate'][0]['name'];
		$serverStatus['Mod'] = $server['Mod'];
		$serverIp       = $server['ServerTemplate']['address'];
	    $serverport     = $server['ServerTemplate']['port'];
	    $serverTemplate = $server['GameTemplate'][0]['name'];

		$this->set('status', $serverStatus);

		if ($server['ServerTemplate']['status'] == 'exec_success'){
			$handle = new COD4ServerStatus($serverIp, $serverport);

		    if ($handle->getServerStatus()){
		    	$handle->parseServerData();
		    	$serverInfo['server'] = $handle->returnServerData();
				$players = $handle->returnPlayers();
				$pings   = $handle->returnPings();
				$scores  = $handle->returnScores();
				// Обработать игроков и посчитать их количество
				$bots = 0;
				$players = 0;
				$playerInfo = array();
				foreach ( $handle->returnPlayers() as $i => $playerName ) {
       				if (preg_match('/^bot[\d]{1,5}/i', $playerName) && $pings[$i] == 999){
       					$bots++;
       				}
       				else
       				{
       					$players++;
       					// Максимальная длина имени игрока - 16 символов
       					/* TODO: Сделать обрезку строки без учета
       					 * спец-символов COD
       					 */

       					if (mb_check_encoding($playerName, 'CP1251')){
							$playerName = mb_convert_encoding($playerName,'UTF-8', 'CP1251')."\n";
						}

						if (strlen($playerName) > 30){
       						$playerName = substr($playerName, 0, 30)."*";
       					}

       					$playerInfo['name']  = $this->codColorText($playerName);
       					$playerInfo['score'] = $scores[$i];
       					$playerInfo['ping']  = $pings[$i];
       					$serverInfo['players'][] = $playerInfo;
       				}
				}

				$serverInfo['server']['clients'] = $players;
				$serverInfo['server']['bots']    = $bots;

				// Раскрасим некоторые текстовки, как в COD
				if (mb_check_encoding($serverInfo['server']['sv_hostname'], 'CP1251')
						and
					$serverTemplate != 'cod4'
						and
					$serverTemplate != 'cod4fixed')
				{
					$serverInfo['server']['sv_hostname'] = mb_convert_encoding($serverInfo['server']['sv_hostname'],'UTF-8', 'CP1251')."\n";
				}

				$serverInfo['server']['sv_hostname'] = $this->codColorText($serverInfo['server']['sv_hostname']);
				if (!empty($serverInfo['server']['_Mod'])){
					$serverInfo['server']['_Mod'] = $this->codColorText($serverInfo['server']['_Mod']);
				}
				else
				{
					$serverInfo['server']['_Mod'] = $server['ServerTemplate']['mod'];
					$serverInfo['server']['_ModVer'] = '';
				}
				$this->set('info', $serverInfo);

				if (!empty($serverInfo['server']))
				{
					$map = $serverInfo['server']['mapname'];
				}
				else
				{
					$map = $server['ServerTemplate']['map'];
				}

			    $this->set('mapDesc', $this->mapDesc($server['GameTemplate'][0]['id'], $map));
		    }
		}

		$this->set('graphs', $this->getStatGraph($id));
	}

	function viewUeds ( $id = null ){
		$this->DarkAuth->requiresAuth();
		$this->loadModel('ServerTemplate');
		$this->layout = 'ajax';

		if (!empty ($id)) {
			$this->ServerTemplate->id = $id;
			$this->set('serverId', $id);
		}

		$server = $this->ServerTemplate->read();

		$serverStatus['status'] = $server['ServerTemplate']['status'];
		$serverStatus['statusDescription'] = $server['ServerTemplate']['statusDescription'];
		$serverStatus['statusTime'] = $server['ServerTemplate']['statusTime'];
		$serverStatus['GameTemplate']['name'] = $server['GameTemplate'][0]['name'];
		$serverIp       = $server['ServerTemplate']['address'];
	    $serverPort     = $server['ServerTemplate']['port'];
	    $serverTemplate = $server['GameTemplate'][0]['name'];

		$this->set('status', $serverStatus);

		if ($server['ServerTemplate']['status'] == 'exec_success'){
			App::import('Vendor', 'GameQ', array('file'=>'game_q.php'));
			$handle = new GameQ();
		    $handle->addServer('serverAnswer', array($serverTemplate, $serverIp, $serverPort + 1));
		    $handle->setOption('timeout', 500);
			$answer = $handle->requestData();

			if (@$answer['serverAnswer']['gq_online'] == 1){
				$info['server']['ip'] = $serverIp;
				$info['server']['panelPort'] = ($serverPort - 7707) + 8075;
				$info['server']['hostname'] = $answer['serverAnswer']['servername'];
				$info['server']['version'] = $answer['serverAnswer']['ServerVersion'];
				$info['server']['mapname'] = $answer['serverAnswer']['mapname'];
				$info['server']['clients'] = $answer['serverAnswer']['playercount'];
				$info['server']['maxclients'] = $answer['serverAnswer']['maxplayers'];
				$info['server']['vac'] = settype($answer['serverAnswer']['IsVacSecured'], 'bool');

				if (!empty($answer['serverAnswer']['GamePassword'])){
					$info['server']['pswrd'] = settype($answer['serverAnswer']['GamePassword'], 'bool');
				}
				else
				{
					$info['server']['pswrd'] = false;
				}

				// Список игроков
				if (!empty($answer['serverAnswer']['players'])){
					$info['server']['players'] = array();
					foreach ( $answer['serverAnswer']['players'] as $player ) {
	       				$player['name'] = $this->codColorText($player['name']);
	       				$info['server']['players'][] = $player;
					}
				}

				$this->set('info', $info);
			}
		}
	}

	function getStatus(){
		$this->DarkAuth->requiresAuth();
		$this->layout = 'ajax';
		$this->loadModel('ServerTemplateProtocol');
		// Получим массив из ID требуемого списка серверов

		if (!empty($this->params['url']['id'])){
			$ids = split(':',$this->params['url']['id']);
			// Теперь получить аналогичный массив севреров,
			// привязанных к пользователю
			$rights = $this->DarkAuth->getAccessList();	// Права доступа

			// Админ может опрашивать любые сервы
			if ( $rights['Admin'] == 1 || $rights['GameAdmin'] == 1 )
				{
					$queryList = $ids;
				}
				else
				{
					$sessionUser = $this->DarkAuth->getAllUserInfo();
					$userServers = $sessionUser['Server'];
					// Вот только нам нужен массив аля [0]=>['id']
					if (!empty($userServers)){
						foreach ( $userServers as $userServer ) {
							$sessionUserServers[] = $userServer['id'];
						}

						// А теперь объединим массивы запрашиваемый и
						// принадлежащий клиенту, чтобы убедиться,
						// что запрос идёт только по его серверам

						$queryList = array_intersect($sessionUserServers,$ids);
					}
				}

				if (!empty($queryList)){
					$servers = $this->ServerTemplateProtocol->find('all',
															   array(
																	 'recursive' => '2',
																	 'conditions' => array(
																							'ServerTemplateProtocol.id' => $queryList
																	 					  )
																	 )
															   );
					foreach ( $servers as $server ) {
						//pr($server);
						switch ( $server['ServerTemplateProtocol']['status'] ) {

							case null:
							case 'stoped':
							case 'stopped':
								$status[$server['ServerTemplateProtocol']['id']] = 'stoped';
								break;
							case 'update_started':
								$status[$server['ServerTemplateProtocol']['id']] = 'updating';
								break;
							case 'exec_error':
							case 'update_error':
								$status[$server['ServerTemplateProtocol']['id']] = 'error';
								break;
							case 'exec_success':
							// Если в базе стоит статус "Запущен", это еще не значит, что
							// сервер реально работает - могут быть ошибки запуска и всё такое
							// Поэтому надо опросить сервер.
								// Серверы на базе HLDS
								// Определяю их по типу, а не протоколу, т.к. эта
								// библиотека по-своему опрашивает
								if ($server['GameTemplate'][0]['Type'][0]['name'] == "hlds"){
									$rcon = new ValveRcon('', $server['ServerTemplateProtocol']['address'], $server['ServerTemplateProtocol']['port'], ValveRcon::PROTO_CLASSIC);
									// Попытка подключиться к серверу
									try  {
										$rcon->connect();
										$status[$server['ServerTemplateProtocol']['id']] = 'running';
										$rcon->disconnect();
									}
									catch (Exception $e)
									{

										$status[$server['ServerTemplateProtocol']['id']] = 'error';
									}
								}
								// Серверы на базе SOURCE
								else if ($server['GameTemplate'][0]['Protocol'][0]['name'] == "source")
								{
									$rcon = new ValveRcon('', $server['ServerTemplateProtocol']['address'], $server['ServerTemplateProtocol']['port'], ValveRcon::PROTO_SOURCE);
									// Попытка подключиться к серверу
									try  {
										$rcon->connect();
										$status[$server['ServerTemplateProtocol']['id']] = 'running';
										$rcon->disconnect();
									}
									catch (Exception $e)
									{
										$status[$server['ServerTemplateProtocol']['id']] = 'error';
									}
								}
								// Серверы на базе COD
								else if ($server['GameTemplate'][0]['Protocol'][0]['name'] == "quake3")

								{
									$handle = new COD4ServerStatus($server['ServerTemplateProtocol']['address'], $server['ServerTemplateProtocol']['port']);

								    if ($handle->getServerStatus()){
								    	$status[$server['ServerTemplateProtocol']['id']] = 'running';
								    }
								    else
								    {
								    	$status[$server['ServerTemplateProtocol']['id']] = 'error';
								    }
								}
								//  Статус серверов, что не опрашиваем, берём из базы
								else
								{
									$status[$server['ServerTemplateProtocol']['id']] = 'running';
								}




								break;

							default:
								break;
						}

					}

					$this->set('status', $status);
					//pr($status);
				}
			}


	}

	/* Тут будем выводить список тестовых серверов
	 * По умолчанию выводить один случайный из публичных серверов
	*/
	function getInfoStatus ($size = 'rand',$game = 'all', $output = 'json'){
		$this->layout = 'ajax';

		if (!empty ($this->params['url']['size'])){
			$size = $this->params['url']['size'];
		}

		if (!empty ($this->params['url']['game'])){
			$game = $this->params['url']['game'];
		}

		if (!empty ($this->params['url']['output'])){
			$output = $this->params['url']['output'];
		}

		$this->loadModel('GameTemplate');
		// Получим массив из ID требуемого списка серверов
		if (!empty($this->params['url']['id'])){
			$ids = array_unique(split(':',$this->params['url']['id']));
			if (!empty($ids)){
				$conditions = array(
									'Server.id' => $ids,
									'status' => 'exec_success',
									'publicStat' => '1'
			 					  );
			}
		}
		else
		{
			$conditions = array(
									'status' => 'exec_success',
									'publicStat' => '1'
								  );

		}

		Cache::set(array('duration' => '+15 minutes'));

		if ($game == 'all'){
			// Нефиг запрашивать лишнюю информацию из базы
			$this->Server->unbindModel(array(
											'hasAndBelongsToMany' => array(
																'Type',
																'Mod',
																'Plugin',
																'Location',
																'RootServer',
																'Service',
																'Order',
																'User',
																'VoiceMumbleParam',
																'RadioShoutcastParam'
													)));
			$this->Server->GameTemplate->unbindModel(array(
											'hasAndBelongsToMany' => array(
																'Mod',
																'Plugin',
																'Config',
																'Service',
																'Server'
													)));
			$this->Server->bindModel(array(
													'hasAndBelongsToMany' => array(
																		'RootServer' => array( 'joinTable' => 'servers_root_servers' )
													)));
			$this->Server->RootServer->unbindModel(array(
											'hasAndBelongsToMany' => array(
																'RootServerIp'
													)));
			$this->Server->RootServer->bindModel(array(
													'hasAndBelongsToMany' => array(
																		'Location' => array( 'fields' => 'id, name, collocation', )
													)));

			if (($servers = Cache::read('serversAllPublic')) === false) {

				$servers = $this->Server->find('all',
												   array(
														 'recursive' => '2',
														 'limit' => 30,
														 'conditions' => $conditions
														 )
												   );

				Cache::set(array('duration' => '+15 minutes'));
				Cache::write('serversAllPublic', $servers);
			}

			if (count($servers) > 10)
			{
				$randServersKeys = array_rand($servers, 10);

				foreach ( $randServersKeys as $key ) {
	       			$randServers[$key] = $servers[$key];
				}
				$servers = $randServers;
			}
		}
		else
		{
			// Нефиг запрашивать лишнюю информацию из базы
			$this->GameTemplate->unbindModel(array(
											'hasAndBelongsToMany' => array(
																'Mod',
																'Plugin',
																'Config',
																'Service',
																'Server'
													)));
			$this->GameTemplate->bindModel(array(
													'hasAndBelongsToMany' => array(
																		'Server' => array(
																							'fields' => 'id, name, desc, address, port, slots',
																							'conditions' => $conditions
																						  ),
																		'Protocol' => array ()
													)));
			$this->GameTemplate->Server->unbindModel(array(
											'hasAndBelongsToMany' => array(
																'Type',
																'Mod',
																'Plugin',
																'Location',
																'RootServer',
																'Service',
																'Order',
																'User',
																'VoiceMumbleParam',
																'RadioShoutcastParam'
													)));
			$this->GameTemplate->Server->bindModel(array(
													'hasAndBelongsToMany' => array(
																		'RootServer' => array( 'joinTable' => 'servers_root_servers' )
													)));
			$this->GameTemplate->Server->RootServer->unbindModel(array(
											'hasAndBelongsToMany' => array(
																'RootServerIp'
													)));
			$this->GameTemplate->Server->RootServer->bindModel(array(
													'hasAndBelongsToMany' => array(
																		'Location' => array( 'fields' => 'id, name, collocation', )
													)));

			if (($serversByGame = Cache::read('serversBy_'.$game)) === false) {

				$serversByGame = $this->GameTemplate->find('all',
															   array(
																	 'recursive' => '3',
																	 'conditions' => array ('name' => $game)
																	 )
															   );

				Cache::set(array('duration' => '+15 minutes'));
				Cache::write('serversBy_'.$game, $serversByGame);
			}


			if (!empty($serversByGame)){
				$i = 0;
				foreach ( $serversByGame[0]['Server'] as $serverByGame ) {
					//pr($serverByGame);
					$servers[$i]['Server'] = $serverByGame;
					$servers[$i]['GameTemplate'][0] = $serversByGame[0]['GameTemplate'];
					$servers[$i]['GameTemplate'][0]['Type'] = $serversByGame[0]['Type'];
					$servers[$i]['RootServer'] = $serverByGame['RootServer'];
					unset($servers[$i]['Server']['RootServer']);
					$servers[$i++]['GameTemplate'][0]['Protocol'] = $serversByGame[0]['Protocol'];

				}
				unset($serversByGame);
			}
		}

		// Составить список игр для выбора
		if ($output != 'json'){
			$this->Server->Type->bindModel(array(
													'hasAndBelongsToMany' => array(
																		'GameTemplate' =>
																				array( 'fields' => 'name, longname',
																					// ВРЕМЕННО исключаю серверы, которых нет в аренде
																								   'conditions' =>
																								   		array ( 'NOT' => array ( 'name' => array ( 'hlmp',
																								 												   'hl1',
																								 												   'dmc',
																								 												   'hl2mp'

																								 				))))
													)));
			$types = $this->Server->Type->find('all', array( 'fields' => 'id, name',
															 'conditions' => array ( 'NOT' => array( 'name' => array('voice', 'chat', 'radio') ),
															 						'active' => '1'

															 )));

			$gameTemplates = $this->GameTemplate->find('all', array ( 'fields' => 'name, longname'));
			$gameTemplatesList = array();
			$gameTemplatesList['all'] = 'Случайные 10 серверов';
			foreach ( $types as $type ) {
				foreach ( $type['GameTemplate'] as $gameTemplate ) {
       				$gameTemplatesList[$gameTemplate['name']] = $gameTemplate['longname'];
				}
			}
			asort($gameTemplatesList);
			$this->set('gameTemplatesList', $gameTemplatesList);
			$this->set('gameTemplateCurrent', $game);
		}

		if (!empty($servers)){

				if ($size == 'rand'){ // Выбрать из полученного списка один случайный
					$rand_id = array_rand($servers);
					$rand_server = $servers[$rand_id];
					$servers = array();
					$servers[0] = $rand_server;
				}

				// Опрос полученного списка
				// Но сначала проверить кэш
				$maps = array();

				foreach ( $servers as $server ) {

					$ip = $server['Server']['address'];
					$port = $server['Server']['port'];
					$id = $server['Server']['id'];

					Cache::set(array('duration' => '+5 minutes'));


					if ($server['GameTemplate'][0]['Protocol'][0]['name'] == "halflife"){

						if (($status[$id] = Cache::read('server_info_'.$id)) === false) {

							// Попытка подключиться к серверу
							try {
								SteamSocket::setTimeout(200);
								$handle = new GoldSrcServer(new InetAddress($ip), $port);
								$handle->initialize();

							}
							catch (Exception $e)
							{
								//pr($e);
							}

							try {
									$status[$id] = $handle->getServerInfo();
								}
							catch( Exception $e ) {
									$status[$id] = false;
							}

							Cache::set(array('duration' => '+5 minutes'));
							Cache::write('server_info_'.$id, $status[$id]);
						}

						if ($status[$id] !== false){

							if (!empty($status[$id]['mapName'])){
								$maps[] = $status[$id]['mapName'];
							}

							$status[$server['Server']['id']]['serverName'] = htmlspecialchars($status[$server['Server']['id']]['serverName']);
						}
					}
					else
					if ($server['GameTemplate'][0]['Protocol'][0]['name'] == "source"){

						if (($status[$id] = Cache::read('server_info_'.$id)) === false) {

							// Попытка подключиться к серверу
							try {
								SteamSocket::setTimeout(200);
								$handle = new SourceServer(new InetAddress($ip), $port);
								$handle->initialize();

							}
							catch (Exception $e)
							{

								//pr($e);
							}

							try {
									$status[$id] = $handle->getServerInfo();
								}
							catch( Exception $e ) {
								$status[$id] = false;
							}

							Cache::set(array('duration' => '+5 minutes'));
							Cache::write('server_info_'.$id, $status[$id]);
						}

						if ($status[$id] !== false){

							if (!empty($status[$id]['mapName'])){
								$maps[] = $status[$id]['mapName'];
							}

							$status[$server['Server']['id']]['playerNumber'] = $status[$server['Server']['id']]['playerNumber'] - $status[$server['Server']['id']]['botNumber'];
							$status[$server['Server']['id']]['serverName'] = htmlspecialchars($status[$server['Server']['id']]['serverName']);
						}
					}
					else
					if ($server['GameTemplate'][0]['Protocol'][0]['name'] == "quake3"){

						if (($status[$id] = Cache::read('server_info_'.$id)) === false) {


							// Попытка подключиться к серверу
							$handle = new COD4ServerStatus($ip, $port);

						    if ($handle->getServerStatus()){
						    	$handle->parseServerData();
						    	$serverInfo = $handle->returnServerData();
								$players = $handle->returnPlayers();
								$pings   = $handle->returnPings();
								$scores  = $handle->returnScores();
								// Обработать игроков и посчитать их количество
								$bots = 0;
								$players = 0;
								$playerInfo = array();
								foreach ( $handle->returnPlayers() as $i => $playerName ) {
				       				if (preg_match('/^bot[\d]{1,5}/i', $playerName) && $pings[$i] == 999){
				       					$bots++;
				       				}
				       				else
				       				{
				       					$players++;
				       				}
								}

								$status[$server['Server']['id']] =  $serverInfo;

								$status[$server['Server']['id']]['playerNumber'] = $players;
								$status[$server['Server']['id']]['botNumber']  = $bots;
								$status[$server['Server']['id']]['maxPlayers'] = $serverInfo['sv_maxclients'];

								// Раскрасим некоторые текстовки, как в COD
								$status[$server['Server']['id']]['serverName'] = $this->codColorText($serverInfo['sv_hostname']).
														'<br/>'.$this->codColorText(@$serverInfo['_Mod']);
								$status[$server['Server']['id']]['mapName'] = $serverInfo['mapname'];
								$status[$server['Server']['id']]['gameVersion'] = $serverInfo['shortversion'];

								Cache::set(array('duration' => '+5 minutes'));
								Cache::write('server_info_'.$id, $status[$id]);

						    }

						    if ($status[$id] !== false){

								if (!empty($status[$id]['mapName'])){
									$maps[] = $status[$id]['mapName'];
								}
							}
						}

					}

					if (!empty($status[$server['Server']['id']])){

						$status[$server['Server']['id']]['status'] = 'running';

					}
					else
					{
						$status[$server['Server']['id']]['status'] = 'stoped';
					}

					$status[$server['Server']['id']]['ip'] = $ip;
					$status[$server['Server']['id']]['port'] = $port;

					$status[$server['Server']['id']]['gameshort'] = $server['GameTemplate'][0]['name'];
					$status[$server['Server']['id']]['gamefull'] = $server['GameTemplate'][0]['longname'];
					$status[$server['Server']['id']]['desc'] = $server['Server']['desc'];
					$status[$server['Server']['id']]['type'] = $server['GameTemplate'][0]['Type'][0]['name'];
					$status[$server['Server']['id']]['location'] = $server['RootServer'][0]['Location']['0']['name'];

				}

				// Запросить информацию о картах
				if ($size != 'rand' and !empty($maps))
				{
					$this->loadModel('Map');
//					$this->Map->unbindModel(array(
//												'hasAndBelongsToMany' => array(
//																	'GameTemplate'
//														)));

					$maps = $this->Map->find('all', array( 'conditions' => array('Map.name' => $maps),
					  								       'fields' => 'id, name, longname'));

					// Проверить соответствие карты шаблону
					$mapsImages = false;
					foreach ($status as $key => $server){
						foreach ($maps as $map)	{
							if ($map['Map']['name'] == @$server['mapName'])
							{
								foreach($map['GameTemplate'] as $gameTemplate){
									if ($gameTemplate['name'] == $server['gameshort'])
									{
										// Проверить на наличие изображения карты
										if (file_exists(WWW_ROOT.'/img/gameMaps/'.$map['Map']['id'].'.jpg')){
											$status[$key]['mapImage'] = $map['Map'];
											$mapsImages = true;
										}
										break;
									}
								}

							}
						}
					}
				}

				$this->set('status',@$status);
				$this->set('mapsImages', @$mapsImages);


			}

		if ($output != 'json'){
			$this->layout = 'wwwsite';
			$this->render('show_public_status');
		}

	}

	function webHosting(){
		$this->DarkAuth->requiresAuth(array('Admin','BetaTesters'));

		$user = $this->DarkAuth->getUserInfo();



		/* 1) Проверить наличие профиля в ISP
		 *    - Если есть, вывести его данные и состояние
		 *    - Если нет, переход ко 2 пункту
		 * 2) Проверить наличие сервера у клиента:
		 *    - Сервер публичный
		 *    - До окончания осталось услуг на 200р и больше
		 * 3) Если п2 истиный, вывести форму регистрации
		 */

		 $checkStatus = $this->checkWebHosting(strtolower($user['username']));

		 if ($checkStatus == 'exists'){ // п.1
		 	//pr($this->data);
		 	$this->set('canCreateHosting', false);
		 	$this->Session->setFlash('У вас уже подключена услуга Web-хостинг.', 'flash_success');
		 }
		 else if ($checkStatus == 'none')// п.2
		 {

		 	$this->Server->User->unbindModel(array(
											'hasAndBelongsToMany' => array(
																'SupportTicket',
																'Group'

													)));

		    $this->Server->User->bindModel(array(
												'hasAndBelongsToMany' => array(
																	'Server' => array( 'fields' => 'id',
																	    			   'conditions' => array ('privateType' => '0',
																	    			   						  'payedTill > NOW()')
																	    			  )
												)));

		 	$this->Server->User->id = $user['id'];

		 	$user = $this->Server->User->read();

		 	$this->loadModel('ServerTemplate');

		 	// Подготовить запрос о серверах
		 	// Скомпоновать в один, чтобы сократить издержки
		 	foreach ( $user['Server'] as $server ) {
							$serversList[] = $server['id'];
			}

		 	$this->ServerTemplate->bindModel(array(
												'hasAndBelongsToMany' => array(
																	'GameTemplate' => array(
																						'fields' => 'id, name, price'
																						)
												)));

			$servers = $this->ServerTemplate->find('all', array (
																	 'fields' => 'id, slots, payedTill',
																	 'conditions' => array ('id' => $serversList)));

		 	$canCreateHosting = false;
			foreach ( $servers as $server ) {
       			$unixTo = strtotime($server['ServerTemplate']['payedTill']);
				$current = time();
				$hoursLeft = round(($unixTo - $current)/3600);
				$moneyLeft = ($server['GameTemplate'][0]['price']/720)*$hoursLeft*$server['ServerTemplate']['slots'];
				if ($moneyLeft >= 100){ // Пока поставлю лимит 100 рублей

					$canCreateHosting = true;
					break;
				}
			}

			if ($canCreateHosting === true){

				if (empty($this->data)){
					$this->set('canCreateHosting', true);
				}
				else
				{
					// Создание пользователя в ISP Manager

					// Сравнить пароли
					if (trim($this->data['User']['newpasswd']) == trim($this->data['User']['confirmpasswd'])){
						// Проверить домен снова
						if (!empty($this->data['WebHosting']['domain'])){

							$domain = strtolower($this->data['WebHosting']['domain']);
							$form = $this->data;

							if (preg_match('/^[a-zA-Z0-9]{3,60}$/', $domain) > 0){
								$domain .= '.'.$this->data['WebHosting']['domainList'];
							}
							else
							if (preg_match('/^([a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,6}$/', $domain) == 0){
								$this->set('canCreateHosting', true);
								$this->Session->setFlash('В имени домена используются недопустимые символы. Попробуйте подобрать другой.', 'flash_error');
								return false;
							}

							$checkStatus = $this->checkWebHosting( $domain, 'wwwdomain');

							// Проверить, свободен ли домен в ISP Manager
							if ( $checkStatus == 'exists'){
								$this->data = $form;
								$this->set('canCreateHosting', true);
								$this->Session->setFlash('Домен занят. Попробуйте подобрать другой.', 'flash_error');
							}
							else
							if ($checkStatus == 'none'){
								// Домен свободен.
								// Все проверки сделаны, можно делать запрос
								// на создание пользователя.
								$isppanel = @parse_ini_file("../config/external.ini.php", true);

								if (empty($isppanel)) {
									$this->data = $form;
									$this->set('canCreateHosting', true);
									$this->Session->setFlash('Не могу получить данные. Попробуйте позднее. ', 'flash_error');
									return false;
								}

								$isplogin = $isppanel['isp']['login'];
								$isppass  = $isppanel['isp']['pass'];

								// Сначала получить IP для создаваемого домена

								$domainParts = preg_split('/\./', $domain);

								$domCheckStatus = $this->checkWebHosting( $domainParts[1].'.'.$domainParts[2], 'domain');

								if ($domCheckStatus == 'exists' && !empty($this->data['Info']['ip'])){
									$ip = $this->data['Info']['ip'];
								}
								else
								{
									// Пока захардкорю тут IP для других доменов.
									// TODO: Запрашивать список доступных IP и выбирать из них
									$ip = '127.0.0.1';
								}

								// Создание учётной записи
								$data = "authinfo=".$isplogin.':'.$isppass.
										"&out=xml".
										"&func=user.edit".
										"&sok=yes".
										"&name=".$user['User']['username'].
										"&passwd=".$form['User']['newpasswd'].
										"&confirm=".$form['User']['newpasswd'].
										"&owner=root".
										"&ip=".$ip.
										"&domain=".$domain.
										"&preset=FreePackage".
										"&email=".$form['User']['email']
										;

								$response = $this->TeamServer->webGet('https://isp.teamserver.ru/manager/ispmgr', 0, $data, 'POST');

								if ($response !== false){

									$output = $this->parceXmlFromIsp($response);

									if (!empty($output['Error'])){
										if ($output['Error']['code'] == 2){
											// Вероятен случай, когда одновременно могут создать две учётки
											$this->Session->setFlash('Пользователь уже создан.', 'flash_error');
										}
										else
										{
											// Вероятен случай, когда одновременно могут создать две учётки
											$this->Session->setFlash('Возникла ошибка при создании учётной записи. Код ошибки: '.$output['Error']['code'].' -> '.@$output['Error']['obj'], 'flash_error');
										}

									}
									else
									if (!empty($output['Info']['ok']))
									{
										$this->Session->setFlash('Пользователь создан успешно.', 'flash_success');

										// Установить обратный адрес админа в www-домене
										$data = "authinfo=".$isplogin.':'.$isppass.
												"&out=xml".
												"&func=wwwdomain.edit".
												"&elid=".$domain.
												"&sok=yes".
												"&cgi=on".
												"&ssi=on".
												"&admin=".$user['User']['username']."@isp.teamserver.ru".
												"&charset=UTF8"
												;

										$response = $this->TeamServer->webGet('https://isp.teamserver.ru/manager/ispmgr', 0, $data, 'POST');

										// Перегрузить Апач
										$data = "authinfo=".$isplogin.':'.$isppass.
												"&out=xml&func=restart";
										$response = $this->TeamServer->webGet('https://isp.teamserver.ru/manager/ispmgr', 0, $data, 'POST');
										$output = @$this->parceXmlFromIsp($response);

										if (!empty($output['Error'])){
											$this->Session->setFlash('Пользователь создан успешно, но не удалось перегрузить веб-сервер. Сообщите в техподдержку.', 'flash_error');
										}

									}
									else
									{
										$this->Session->setFlash('Вероятно возникла ошибка. Закройте это окно и откройте снова. Если там не будет учётных данных, попробуйте снова или обратитесь в техподдержку.', 'flash_error');
									}

									$this->redirect(array('action' => 'webHosting'));
								}

							}
							else
							{
								$this->data = $form;
								$this->set('canCreateHosting', true);
								$this->Session->setFlash('Возникла ошибка при проверке домена. Попробуйте позже.', 'flash_error');
							}


						}
						else // Пользователь не указал домен
						{
							$this->set('canCreateHosting', true);
							$this->Session->setFlash('Вы не указали домен.', 'flash_error');
						}
					}
					else // Пароли не совпадают
					{
						$this->set('canCreateHosting', true);
						$this->Session->setFlash('Пароли не совпадают.', 'flash_error');

					}
				}
			}
			else
			{
				$this->Session->setFlash('К сожалению, вам недоступна услуга бесплатного Web-хостинга.<br/>' .
										 'Услуга может быть активирована при условии наличия игрового сервера,' .
										 'удовлетворяющего условиям:<br/>' .
										 '- Сервер должен быть публичным<br/>' .
										 '- Стоимость оставшейся аренды должна превышать 100 рублей (без учёта дополнительных услуг)<br/>' .
										 'После активации услуги, она предоставляется бессрочно при наличии любого оплаченного игрового сервера.', 'flash_error');
			}
			// Конец создания формы
		 }
		 else
		 {
		 	// Просто вывести сообщение setFlash
		 	$this->set('canCreateHosting', false);
		 }
	}

	function webHostingCheckDomain ( $domain = null ) {
		$this->layout = 'ajax';
		$this->DarkAuth->requiresAuth();

		if (!empty ($domain)){

			$checkStatus = $this->checkWebHosting( $domain, 'wwwdomain');

			if ($checkStatus == 'exists'){
				$result['exists'] = 1;
				$result['error'] = 0;
			}
			else
			if ($checkStatus == 'none'){
				$result['exists'] = 0;
				$result['error'] = 0;
			}
			else
			{
				$result['error'] = 1;
			}
		}
		else
		{
			$result['error'] = 1;
		}

		$this->set('result', $result);

		return $result;

	}

	function script($id = null, $action = null, $token = null) {

		 if ($token){
		 	$this->loadmodel('ServerClean');
		 	$this->layout = 'simple';
		 	$server = $this->ServerClean->find('first', array(
														'conditions' => array(
																			   'controlToken' => $token
																			  )
														)
										 );
		 	if ($server)
			{
				$byToken = true;
				$id = $server['ServerClean']['id'];

			}
			else
			{
				$this->Session->setFlash('Неверный токен', 'flash_error');
			}


		 }
		 else
		 {
		 	$this->DarkAuth->requiresAuth();
		 }

		 if (@$byToken or $this->checkRights($id)){

		 		// Нефиг запрашивать лишнюю информацию из базы
				$this->Server->unbindModel(array(
													'hasAndBelongsToMany' => array(
																		'Type',
																		'Order',
																		'Mod',
																		'Plugin',
																		'Config',
																		'RootServer',
																		'VoiceMumbleParam',
																		'RadioShoutcastParam'
															)));

		 		$this->Server->id = $id;
		 		$server = $this->Server->read();

		 		$this->Server->GameTemplate->unbindModel(array(
													'hasAndBelongsToMany' => array(
																		'Mod',
																		'Plugin',
																		'Config',
																		'Service',
																		'Protocol'
															)));

		 		$this->Server->GameTemplate->id = $server['GameTemplate'][0]['id'];
		 		$gameTemplate = $this->Server->GameTemplate->read();

		 		$serverIp = $server['Server']['address'];
		 		$serverId = $server['Server']['id'];
		 		$userId   = $server['User'][0]['id'];

		 		$this->set('serverId', $serverId);

				// Составить список сообщений для лога
				$logMessages = array (  'start'   => 'запущен',
										'startDebug'    => 'запущен в режиме отладки',
										'startWithManu' => 'запущен вместе с ManuAdminMod',
										'startHltv'     => 'запущен HLTV',
										'stop'        => 'выключен',
										'stopHltv'    => 'выключен HLTV',
										'restart'     => 'перезапущен',
										'restartHltv' => 'перезапущен HLTV',
										'restartWithManu' => 'перезапущен вместе с ManuAdminMod',
										'update'  => ': запущено обновление',
										'setServerPass' => 'запущен в режиме установки пароля'
										);

				$logErrorMessages = array ( 'start'         => 'запуска',
											'startDebug'    => 'запуска в режиме отладки',
											'startWithManu' => 'запуска вместе с ManuAdminMod',
											'startHltv' => 'запуска HLTV',
											'stop'      => 'выключения',
											'stopHltv'  => 'выключения HLTV',
											'restart'     => 'перезапуска',
											'restartHltv' => 'перезапуска HLTV',
											'restartWithManu' => 'перезапуска вместе с ManuAdminMod',
											'update'  => ': запуска обновления',
											'setServerPass' => 'запуска в режиме установки пароля'
											);

				if (!empty($logMessages[$action])){
					$currentLogMessage = $logMessages[$action];
					$currentErrorLogMessage = $logErrorMessages[$action];
				}
				else
				{
					$currentLogMessage = ': действие -> '.$action;
					$currentErrorLogMessage = 'совершения действия: '.$action;
				}


				$response = '';

				// Если сервер запускается в debug-режиме - Остановить его сначала, потом поставить ключ
				// Иначе проверить его наличие и снять его
				if ($action == "startDebug"){

					$request = "~configurator/scripts/subscript_start_stop.py?s=".$serverId.'&a=stop';
					$response = chop($this->TeamServer->webGet($serverIp, 0, $request, "GET"));

					$action = 'start';

					if ($this->Server->saveField('debug', 1)){
						$this->Session->setFlash('Сервер запущен в режиме отладки и будет отключен автоматически через 30 минут. Результаты читайте в соответсвующих логах.', 'flash_success');
					}
					else
					{
						$this->Session->setFlash('Не удалось запустить сервер в режиме отладки. Обратитесь в техподдержку. Осуществляю попытку запуска в обычном режиме.', 'flash_error');
					}
				}
				else
				if ($server['Server']['debug'] == '1')
				{
					if (!$this->Server->saveField('debug', 0)){
						$this->Session->setFlash('Не удалось отключить режим отладки. Обратитесь в техподдержку. Ошибка: '.mysql_error(), 'flash_error');
					}
				}

				// Режим установки пароля для KillingFloor
				if ($action == 'setServerPass'){
					$action = 'start';

					if ($this->Server->saveField('setAdmPass', 1)){
						$this->Session->setFlash(   'Сервер запущен в режиме установки пароля.<br/>' .
												    'Для доступа к настройкам сервера используйте:<br/>' .
												    ' - логин: admin<br/>' .
												    ' - пароль: '.$serverId.'<br/>'.
												    'После установки новых данных, перезагрузите сервер в обычном режиме!<br/>'.
													'Результаты запуска и работы сервера читайте в соответсвующих логах.', 'flash_success');
					}
					else
					{
						$this->Session->setFlash('Не удалось запустить сервер в режиме установки пароля. Обратитесь в техподдержку. Осуществляю попытку запуска в обычном режиме.', 'flash_error');
					}
				}
				else
				if ($server['Server']['setAdmPass'] == '1'){
					if (!$this->Server->saveField('setAdmPass', 0)){
						$this->Session->setFlash('Не удалось отключить режим установки пароля. Обратитесь в техподдержку. Ошибка: '.mysql_error(), 'flash_error');
					}
				}

				// Создание одноразового токена
				if ($action == 'update' and $server['Server']['status'] != 'update_started')
				{
					if (!$this->Server->saveField('action_token', md5(rand(26858,8000064000).time()) )){
						$this->Session->setFlash('Не удалось создать токен обновления. Обратитесь в техподдержку. Ошибка: '.mysql_error(), 'flash_error');
						$this->render();
						return false;
					}
				}
				else
				if ($action != 'update' and $server['Server']['status'] != 'update_started')
				{
					if (!$this->Server->saveField('action_token', NULL)){
						$this->Session->setFlash('Не удалось очистить токен обновления. Обратитесь в техподдержку. Ошибка: '.mysql_error(), 'flash_error');
					}
				}

				// Совершаем запрос и форматируем вывод
				$data = "s=".$serverId.
						"&a=".$action;

				switch ( $gameTemplate['Type'][0]['name'] ) {
					case 'srcds':
					case 'hlds':
					case 'cod':
					case 'ueds':
						$request = "~configurator/scripts/subscript_start_stop.py?".$data;
						break;

					case 'voice':
						if ($gameTemplate['GameTemplate']['name'] == 'mumble'){
							$request = "~configurator/scripts/subscript_start_stop.py?".$data;
						}
						break;

					default:
						$request = "~client".$userId."/.server_".$action."_".$serverId.".sh";
						break;
				}

		 		$response .= $this->TeamServer->webGet($serverIp, 0, $request, "GET");

		 		if (empty($this->data['Webget']['error']))
		 		{
		 			$this->set('result',chop(@$response));

		 			//Лог успешного запуска
		 			$this->TeamServer->logAction('Сервер '.strtoupper(@$gameTemplate['GameTemplate']['name']).' #'.$serverId.' '.$currentLogMessage, 'ok', $userId);
		 		}
		 		else
		 		{
		 			$this->set('result','<strong>Возникла ошибка при совершении запроса.</strong>');
		 			$this->TeamServer->logAction('Ошибка при попытке '.$currentErrorLogMessage.' сервера '.strtoupper(@$gameTemplate['GameTemplate']['name']).' #'.$serverId, 'error', $userId);
		 		}


				if (@$byToken){
					$this->set('token',$token);
					$this->render('result_for_token');
				}

		 		return true;
		 	}

	}
	/*  Запись статуса обновления и установки
	 *   @token: токен
	 *   @action: read или write - чтение или запись статуса
	 *   @serverAction: update или install - действие
	 *   @status: ok или error - нормальный статус или ошибка
	 *   @statusDesc - готовность в процентах или описание ошибки
	 *   @errorNum - номер ошибки
	 */
	function actionStatus( 	$action = null,
							$serverAction = null,
							$token = null,
							$status = null,
							$statusDesc = null,
							$errorNum = null) {

		$this->layout = 'ajax';



		if (!is_null($action) and in_array($action, array('read', 'write')))
		{
			$this->loadModel('ServerClean');

			if ($action == 'read')
			{
				// При чтении статуса, токен является ID сервера
				$this->DarkAuth->requiresAuth();
				$id = $token;
				if ($this->checkRights($id)){

					$this->ServerClean->id = $id;
					$server = $this->ServerClean->read();

					if (!empty($server['ServerClean']['statusDescription']))
					{
						$status = preg_split('/\:/', $server['ServerClean']['statusDescription']);

						if (!empty($status) and in_array($status[0], array('update', 'install')))
						{
							$this->set('status', array( 'state' => $status[0],
														'progress' => $status[1],
														'time' => $server['ServerClean']['statusTime']));
						}
						else
						if (!empty($status) and $status[0] == 'error')
						{
							$this->set('status', array( 'state' => 'error',
														'error' => $status[2],
								                		'errorNum' => $status[1],
														'time' => $server['ServerClean']['statusTime']));
						}
						else
						{
							$this->set('status', array('error' => 'wrong_status'));
						}

					}

				}
			}
			else if ($action == 'write' and !is_null($serverAction))
			{
				// Искать сервер по одноразовому токену
				if ($server = $this->ServerClean->findByActionToken($this->params['form']['action_token']))
				{
					$this->ServerClean->id = $server['ServerClean']['id'];

					if (in_array($serverAction, array('update', 'install')))
					{
						if ($status == 'error')
						{
							$serverState = "error:".intval($this->params['form']['errorNum']).":".floatval($this->params['form']['statusDesc']);
						}
						else
						{
							$serverState = $serverAction.":".floatval($this->params['form']['statusDesc']);
						}

						if ($this->ServerClean->saveField('statusDescription',$serverState))
						{
							$this->set('status', 'OK');
						}
						else
						{
							$this->set('status', array('error' => 'db_error'));
						}
					}
				}
				else
				{
					// Ошибка - сервер не найден
					$this->set('status', array('error' => 'wrong_server'));
				}
			}
		}
		else
		{
			$this->set('status', array('error' => 'wrong_action'));
		}
	}


	function setMap($id = null, $map = null) {
		 $this->DarkAuth->requiresAuth();
 	 	 if (!empty($this->data)){

		 	$id = @$this->data['Server']['id'];
		 	$action = @$this->data['Server']['action'];
		 	$map = $this->data['Server']['map'];

		 }
		 // Проверим  - владееет ли пользователь сессии этим сервером?
		 if ($this->checkRights($id)){
		 	if (empty($this->data)){
		 		// Нефиг запрашивать лишнюю информацию из базы
				$this->Server->unbindModel(array(
												'hasAndBelongsToMany' => array(
																	'Order',
																	'Mod',
																	'Plugin',
																	'Config',
																	'RootServer',
																	'VoiceMumbleParam',
																	'RadioShoutcastParam'
														)));
			 	$this->Server->id = $id;
			 	$this->data = $this->Server->read();

			 	// Получить список установленных карт для SRCDS и HLDS
			 	if ($this->data['Type'][0]['name'] == 'srcds'
			 			or
			 		$this->data['Type'][0]['name'] == 'hlds'
			 			or
			 		$this->data['Type'][0]['name'] == 'ueds'){

				 		// Ответ сервера закэшировать на 90 секунд - как раз для открытия окна, ввод параметров и обновление
				 		Cache::set(array('duration' => '+90 seconds'));

						if (($xmlAsArray = Cache::read('maps_'.$id)) === false) {

							$data = "id=".$id;
					 		$requestStr = "/~configurator/scripts/subscript_maps_list.py";

					 		$HttpSocket = new HttpSocket();
					 		$response = $HttpSocket->get($this->data['Server']['address'].$requestStr, $data);
					 		//pr($response);
					 		$xml = new Xml($response);
					 		$xmlAsArray = $xml->toArray();


							Cache::set(array('duration' => '+90 seconds'));
							Cache::write('maps_'.$id, $xmlAsArray);
						}


				 		// Прасинг лога и ошибок
					 	$responseMessages = $this->parseXmlResponse($xmlAsArray);
				 		$error = $responseMessages['error'];

				 		// Парсинг списка карт
				 		$mapsList = array();
				 		$mapExt = $this->data['GameTemplate'][0]['mapExt'];
				 		if (!empty($xmlAsArray['Response']['List']['File'])){
				 			foreach ( $xmlAsArray['Response']['List']['File'] as $map ) {
		       					if (preg_match('@^(\S{1,})(?:\.'.$mapExt.')@i', $map, $match)){
		       						$mapsList[$match[1]] = $match[1];
		       						// Для L4D2 надо добавить в список режим сражения
		       						if ($this->data['GameTemplate'][0]['name'] == 'l4d2'
		       							or
		       						    $this->data['GameTemplate'][0]['name'] == 'l4d2-t100'){
		       							$mapsList[$match[1].' versus'] = $match[1].' (Сражение)';
		       						}
		       					}
							}
				 		}
				 		else if (!empty($xmlAsArray['Response']['List']['file']))
				 		{
				 			if (preg_match('@^(\S{1,})(?:\.'.$mapExt.')@i', $xmlAsArray['Response']['List']['file'], $match)){
		   						$mapsList[$match[1]] = $match[1];
		   					}
				 		}
				 		else
				 		{
				 			if (!empty($this->data['Server']['map'])){
				 				$error .= 'Не обнаружено карт сервера вообще, хотя в строке запуска карта установлена! Переинициализируйте сервер, либо загрузите нужную карту и установите её картой по умолчанию!';
				 			}
				 			else
				 			{
				 				$error .= 'Не обнаружено карт сервера вообще!';
				 			}
				 		}
			 		}
			 	/* Конец получения списка карт для SRCDS и HLDS */
			 	else
			 	// Получить список установленных карт для COD
			 	if ($this->data['Type'][0]['name'] == 'cod'){

				 		// Ответ сервера закэшировать на 90 секунд - как раз для открытия окна, ввод параметров и обновление
				 		Cache::set(array('duration' => '+90 seconds'));

						if (($xmlAsArray = Cache::read('maps_'.$id)) === false) {

							$data = "id=".$id;
				 			$requestStr = "/~configurator/scripts/subscript_cod_maps_list.py";

					 		$HttpSocket = new HttpSocket();
					 		$response = $HttpSocket->get($this->data['Server']['address'].$requestStr, $data);
					 		//pr($response);
					 		$xml = new Xml($response);
					 		$xmlAsArray = $xml->toArray();


							Cache::set(array('duration' => '+90 seconds'));
							Cache::write('maps_'.$id, $xmlAsArray);
						}


				 		// Прасинг лога и ошибок
				 		$responseMessages = $this->parseXmlResponse($xmlAsArray);
				 		$error = $responseMessages['error'];

				 		//pr($xmlAsArray);
				 		// Парсинг списка карт - директории (пользовательские карты)
				 		// Может быть куча вариантов xml-массива, потому предварительная обработка

				 		// Для CoD2 необходиму вручную ввести список стоковых карт
				 		if ($this->data['GameTemplate'][0]['name'] == 'cod2'){
				 			$mapsList = array(  'mp_breakout'     => '(Франция) Villers-Bocage',
				 								'mp_brecourt'     => '(Франция) Brecourt',
				 								'mp_burgundy'     => '(Франция) Burgundy',
				 								'mp_carentan'     => '(Франция) Carentan',
				 								'mp_dawnville'    => '(Франция) St. Mere Eglise',
				 								'mp_trainstation' => '(Франция) Caen',
				 								'mp_farmhouse'    => '(Франция) Beltot',
				 								'mp_downtown'  => '(Россия) Moscow',
				 								'mp_railyard'  => '(Россия) Stalingrad',
				 								'mp_leningrad' => '(Россия) Leningrad',
				 								'mp_harbor'    => '(Россия) Rostov',
				 								'mp_matmata' => '(Африка) Matmata',
				 								'mp_toujane' => '(Африка) Toujane',
				 								'mp_decoy'   => '(Африка) El Alamein',
				 								'mp_rhine' => '(Германия) Wallender'
				 								);
				 		}
				 		else
				 		{
				 			$mapsList = array();
				 		}

				 		$mapExt = $this->data['GameTemplate'][0]['mapExt'];

				 		if ( !empty($xmlAsArray['Response']['List'])
				 					and
				 			 count($xmlAsArray['Response']['List'] > 0)){
				 			$dirList = array();
				 			$fileList = array();

				 			foreach( $xmlAsArray['Response']['List'] as $list){
				 				if (!empty($list['Dir'])){
				 					$dirList = array_merge($dirList, $list['Dir']);
				 				}
				 				else
				 				if (!empty($list['dir']))
				 				{
				 					$dirList = array_merge($dirList, $list['dir']);
				 				}
				 				else
				 				if (!empty($list['File']))
				 				{
				 					$fileList = array_merge($fileList, $list['File']);
				 				}
				 				else
				 				if (!empty($list['file']))
				 				{
				 					$fileList = array_merge($fileList, $list['file']);
				 				}
				 			}
				 		}
				 		else
				 		{
				 			if (!empty($this->data['Server']['map'])){
				 				//Для CoD2 не выводить это сообщение
				 				if ($this->data['GameTemplate'][0]['name'] != 'cod2'){
				 					$error .= 'Не обнаружено карт сервера вообще, хотя в строке запуска карта установлена! Переинициализируйте сервер, либо загрузите нужную карту и установите её картой по умолчанию!';
				 				}
				 			}
				 			else
				 			{
				 				$error .= 'Не обнаружено карт сервера вообще!';
				 			}
				 		}

				 		if (!empty($dirList)){
					 		foreach ( $dirList as $map ) {
		       						$mapsList[$map] = 'usermaps/'.$map;
							}
			 			}

			 			if (!empty($fileList)){
					 		foreach ( $fileList as $map ) {
		       						if (preg_match('@^(\S{1,})(?:\.'.$mapExt.')@i', $map, $match)){
			       						$mapsList[$match[1]] = $match[1];
			       					}
							}
			 			}
			 		}
			 		/* Конец получения списка карт для COD */

		 		if (!empty($mapsList)){
		 			asort($mapsList);
		 			if ($this->data['Type'][0]['name'] == 'cod'){
		 				$mapsList['rotate'] = 'Авто-ротация карт';
		 			}
		 			$this->data['Server']['maps'] = $mapsList;
		 		}

		 		// Проверим принципиальное наличие карты по-умолчанию на сервере
		 		if (!empty($mapsList)
		 				and
		 			!empty($this->data['Server']['map'])
		 				and
		 			!array_key_exists($this->data['Server']['map'], $mapsList)){
		 				$error .= 'В качестве карты по-умолчанию установлена карта, которой физически нет на сервере! Сервер не сможет быть запущен! Загрузите карту на сервер либо установите другую карту по-умолчанию!';
		 			}

		 		// Вывод ошибки
		 		if (@$error != ''){
		 			$this->Session->setFlash('Возникла ошибка при получении списка установленных карт: '.$error, 'flash_error');
		 		}
		 		/* Конец чтения доступных карт */
		 	}
		 	else
		 	{
		 		$map = trim(strip_tags($this->data['Server']['map']));

		 		if (preg_match('@^\W@i', $map, $match)){
		 			$this->Session->setFlash('Нельзя установить карту по-умолчанию со служебным символом '.$match[0].' в начале названия. Но вы по-прежнему можете использовать её в игре.', 'flash_error');
		 		}
		 		else if ($this->Server->saveField('map', $map)){
		 			$this->Session->setFlash('Смена карты прошла успешно. Перезапустите сервер.', 'flash_success');
		 		}
		 		else
		 		{
	 		 		$this->Session->setFlash('Произошла ошибка: '.mysql_error(), 'flash_error');
		 		}

		 		$this->redirect(array('action'=>'editStartParams',$id));
		 	}
		 }
	}

	// Установка набора карт для CS:GO
	function setMapGroup ( $id = null ) {
		$this->DarkAuth->requiresAuth();

		if (is_null($id)){
			$id = $this->data['Server']['id'];
		}

		// Проверим  - владееет ли пользователь сессии этим сервером?
		if ($this->checkRights($id)){
			if (!empty($this->data))
			{

				$this->loadModel('ServerClean');

				// Вычистить потенциальную XSS-атаку
				$mapGroup = trim(strip_tags($this->data['Server']['mapGroup']));

				$this->ServerClean->id = $id;

				if ($this->ServerClean->saveField('mapGroup', $mapGroup))
				{
					$this->Session->setFlash('Установка группы карт прошла успешно. Перезапустите сервер.', 'flash_success');
				}
				else
		 		{
	 		 		$this->Session->setFlash('Произошла ошибка: '.mysql_error(), 'flash_error');
		 		}
		 	}
		}

		$this->redirect(array('action'=>'editStartParams',$id));
	}

	// Установка режима игры для CS:GO
	function setGameMode ( $id = null ) {
		$this->DarkAuth->requiresAuth();

		if (is_null($id)){
			$id = $this->data['Server']['id'];
		}

		// Проверим  - владееет ли пользователь сессии этим сервером?
		if ($this->checkRights($id)){
			if (!empty($this->data))
			{

				$this->loadModel('ServerClean');

				// Вычистить потенциальную XSS-атаку
				$gameMode = trim(strip_tags($this->data['Server']['gameMode']));

				$this->ServerClean->id = $id;

				if ($this->ServerClean->saveField('mod', $gameMode))
				{
					$this->Session->setFlash('Установка режима игры прошла успешно. Перезапустите сервер.', 'flash_success');
				}
				else
		 		{
	 		 		$this->Session->setFlash('Произошла ошибка: '.mysql_error(), 'flash_error');
		 		}
		 	}
		}

		$this->redirect(array('action'=>'editStartParams',$id));
	}

	// Установка authkey для CS:GO
	function setHostMap ( $id = null ) {
		$this->DarkAuth->requiresAuth();

		if (is_null($id)){
			$id = $this->data['Server']['id'];
		}

		// Проверим  - владееет ли пользователь сессии этим сервером?
		if ($this->checkRights($id)){
			if (!empty($this->data))
			{

				$this->loadModel('ServerClean');

				// Вычистить потенциальную XSS-атаку
				$hostMap = trim(strip_tags($this->data['Server']['hostmap']));

				$this->ServerClean->id = $id;

				if ($this->ServerClean->saveField('hostmap', $hostMap))
				{
					$this->Session->setFlash('Установка Host Map прошла успешно. Перезапустите сервер.', 'flash_success');
				}
				else
		 		{
	 		 		$this->Session->setFlash('Произошла ошибка: '.mysql_error(), 'flash_error');
		 		}
		 	}
		}

		$this->redirect(array('action'=>'editStartParams',$id));
	}

	// Установка Host Map для CS:GO
	function setHostCollection ( $id = null ) {
		$this->DarkAuth->requiresAuth();

		if (is_null($id)){
			$id = $this->data['Server']['id'];
		}

		// Проверим  - владееет ли пользователь сессии этим сервером?
		if ($this->checkRights($id)){
			if (!empty($this->data))
			{

				$this->loadModel('ServerClean');

				// Вычистить потенциальную XSS-атаку
				if ($this->data['Server']['hostCollectionList'] != 0)
				{
					$hostCollection = trim(strip_tags($this->data['Server']['hostCollectionList']));
				}
				else
				if ($this->data['Server']['hostCollectionList'] == 0 and empty($this->data['Server']['hostcollection']))
				{
					$hosCollection = NULL;
				}
				else
				{
					$hostCollection = trim(strip_tags($this->data['Server']['hostcollection']));
				}

				$this->ServerClean->id = $id;

				if ($this->ServerClean->saveField('hostcollection', $hostCollection))
				{
					$this->Session->setFlash('Установка Host Collection прошла успешно. Перезапустите сервер.', 'flash_success');
				}
				else
		 		{
	 		 		$this->Session->setFlash('Произошла ошибка: '.mysql_error(), 'flash_error');
		 		}
		 	}
		}

		$this->redirect(array('action'=>'editStartParams',$id));
	}

	// Установка запускаемого мода COD
	function codSetMod ($id = null, $mod = null) {
		 $this->DarkAuth->requiresAuth();
 	 	 if (!empty($this->data)){

		 	$id = @$this->data['Server']['id'];
		 	$action = @$this->data['Server']['action'];
		 	$mod = trim($this->data['Server']['mod'],'.,/\\');

		 }
		 // Проверим  - владееет ли пользователь сессии этим сервером?
		 if ($this->checkRights($id)){
		 	if (!empty($this->data))
		 	{
		 		if ($this->data['Server']['mod'] == ''){
		 			$this->data['Server']['mod'] = NULL;
		 		}
		 		if ($this->Server->saveField('mod', $this->data['Server']['mod'])){
		 			$this->Session->setFlash('Смена мода прошла успешно. Перезапустите сервер.', 'flash_success');
		 		}
		 		else
		 		{
	 		 		$this->Session->setFlash('Произошла ошибка: '.mysql_error(), 'flash_error');
		 		 }

		 		 $this->redirect(array('action'=>'editStartParams',$id));
		 	}
		 }
	}

	function setRconPassword ( $id = null, $action = null ) {
		 $this->DarkAuth->requiresAuth();

		 if (is_null($id)){
		 	$id = $this->data['Server']['id'];
		 }

		 // Проверим  - владееет ли пользователь сессии этим сервером?
		 if ($this->checkRights($id)){
		 	if (!empty($this->data))
		 	{
		 		$this->loadModel('ServerType');
		 		$this->ServerType->id = $id;

		 		$server = $this->ServerType->read();

		 		if ($this->Server->saveField('rconPassword',trim($this->data['Server']['rconPassword']))){

		 			if (!empty($server['Type'][0]['name'])
		 					and
		 					(
		 						$server['Type'][0]['name'] == 'srcds'
		 							or
		 						$server['Type'][0]['name'] == 'hlds'
		 					)
		 				)
		 			{
		 				$this->data['Server']['paramName'] = 'rcon_password';
		 				$this->data['Server']['paramValue'] = trim($this->data['Server']['rconPassword']);
		 				if($this->setConfigParam( $id, 'return')){
		 					$this->Session->setFlash('Пароль RCON сохранён и прописан успешно. Перезапустите сервер.', 'flash_success');
		 					$result = true;
		 				}
		 				else
		 				{
		 					$result = false;
		 				}
		 			}
		 			else
		 			{
		 				$this->Session->setFlash('Пароль RCON установлен успешно. Перезапустите сервер.', 'flash_success');
		 				$result = true;
		 			}

		 		}
		 		else
		 		{
	 		 		$this->Session->setFlash('Произошла ошибка: '.mysql_error(), 'flash_error');
	 		 		$result = false;
		 		 }

		 	}
		 	else
		 	{
		 		$result = false;
		 	}
		 }
		 else
		 {
		 	$result = false;
		 }
		 if ($action == 'return')
		 {
		 	return $result;
		 }
		 else
		 {
		 	$this->redirect(array('action'=>'editStartParams',$id));
		 }

	}

	// Создание администратора сервера
	function setModAdmin ( $id = null){
		 if (is_null($id)){
		 	$id = $this->data['Server']['id'];
		 }
		 // Проверим  - владееет ли пользователь сессии этим сервером?
		 if ($this->checkRights($this->data['Server']['id'])){

		 	// Отключить лишние запросы
				$this->Server->unbindModel(array(
													'hasAndBelongsToMany' => array(
																		'Plugin',
																		'Service',
																		'Order',
																		'User',
																		'Location',
																		'RootServer',
																		'VoiceMumbleParam',
																		'RadioShoutcastParam'
															)));
		 		$this->Server->id = $id;

		 		$server = $this->Server->read();

		 		if (!empty($server['Mod'])){
		 			// Определение переменных
		 			$admType = '';
		 			$modFound = false;
		 			$admString = trim($this->data['Server']['admString']);

		 			// Распознать введёный параметр
		 			if (preg_match('/^STEAM_[01]:[01]:[0-9]{4,11}$/', $admString) > 0){ // STEAM ID
		 				$admType = 'steam';
		 			}
		 			else
		 			if (preg_match('/^\"[0-9a-zA-Z-_\$@\+\=\^\!\?]+\"\s+\"[0-9a-zA-Z-_\$@\+\=\^\!\?]+\"$/', $admString) > 0){ // IP
		 				$admType = 'userPass';
		 			}
		 			else
		 			if (preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$/', $admString) > 0){ // IP
		 				$admType = 'ip';
		 			}
		 			else
		 			{
		 				$this->Session->setFlash('Не удалось распознать введёную строку.', 'flash_error');
		 			}

		 			// Теперь проходимся по модам и в зависимотси от него прописываем параметры
		 			// Для SourceMod и Amxmodx используем простую запись параметра.
		 			// Для Maniadmin придется отдельную библиотеку.

		 			foreach ( $server['Mod'] as $mod ) {
       					if ( in_array($mod['name'], array('sourcemod', 'maniadmin', 'amxmodx')) ){
       						$modFound = true;
       						$data = 'id='.$id.
									'&mod='.$mod['name'].
       								'&adminType='.$admType.
       								'&adminStr='.$admString;

       						$requestStr = '/~configurator/scripts/subscript_write_admin_to_mod.py';

							$HttpSocket = new HttpSocket();
							$response = $HttpSocket->get($server['Server']['address'].$requestStr, $data);

					 		$xml = new Xml($response);
					 		$xmlAsArray = $xml->toArray();

							// Прасинг лога и ошибок
					 		$responseMessages = $this->parseXmlResponse($xmlAsArray);
					 		$error = $responseMessages['error'];
					 		$log   = $responseMessages['log'];

					 		if (empty($responseMessages['error'])){

								$this->Session->setFlash('Администратор создан успешно успешно. Перезагрузите сервер.', 'flash_success');

							}
							else
							{
								$error = $error.'Лог выполнения задания:<br/>'.$log;
								$this->Session->setFlash('При попытке записи администратора '.
														 ' возникла ошибка:<br/>'.$error, 'flash_error');
							}


       					}

					}

		 			if ($modFound === false){
		 				$this->Session->setFlash('Не найдено ни одного мода, куда можно прописать админа.', 'flash_error');
		 			}

		 		}
		 		else
		 		{
		 			$this->Session->setFlash('Не установлено ни одного мода.', 'flash_error');
		 		}

		 }
		 $this->redirect(array('action'=>'editStartParams',$id));
	}

	/* Установка параметра в конфиге
	 * @id - ID сервера
	 * @back - redir (переадресация), return (вернуть значения)
	 * @redir - куда делать переадресациию
	 */
	function setConfigParam ( $id = null, $back = 'redir', $redir = 'editStartParams'){

		$this->DarkAuth->requiresAuth();

		if (is_null($id)){
		 	$id = $this->data['Server']['id'];
		}
		// Проверим  - владееет ли пользователь сессии этим сервером?
		 if ($this->checkRights($id)){

		 	// Подготовить параметры и значения
		 	// Убрать у параметра все /, ", ' и перевод строки
		 	$paramName = preg_replace('/(\/|"|\'|\n)/', '', $this->data['Server']['paramName']);

		 	// Теперь проверить на наличие запрещенного параметра
		 	// Мало ли, подсунет поле в форму.

		 	if (!$this->checkForBlockedParam ($paramName)){
		 		// Отключить лишние запросы
				$this->Server->unbindModel(array(
													'hasAndBelongsToMany' => array(
																		'Mod',
																		'Plugin',
																		'Service',
																		'Order',
																		'User',
																		'RootServer',
																		'VoiceMumbleParam',
																		'RadioShoutcastParam'
															)));
		 		$this->Server->id = $id;

		 		$server = $this->Server->read();

		 		// Для большей наглядности, обозначим некоторые параметры
		 		// Убрать у значения параметра все пробелы, /, ", '
			 	$paramValue = preg_replace('/(\/|"|\')/', '', $this->data['Server']['paramValue']);

		 		switch ( $paramName ) {
					case 'sv_password':
					case 'set g_password':
					case 'GamePassword':

						$paramDesc = 'Пароль сервера';
						$message   = 'установлен';
						break;

					case 'rcon_password':
						$paramValue = preg_replace('/(\/|"|\'|\s)/', '', $this->data['Server']['paramValue']);
						$paramDesc = 'Пароль RCON';
						$message   = 'установлен';
						break;

					case 'hostname':
					case 'set sv_hostname':
					case 'sets sv_hostname':
					case 'ServerName':

						$paramDesc = 'Имя сервера';
						$message = 'изменено';
						break;

					default:
						$paramDesc = 'None';
						break;
				}

			 	// Пописать правильно, если параметр пуст
			 	if ($paramValue == ''){
			 		$paramValue = 'None';
			 	}

		 		/* Выбрать конфиг, в который писать по типу сервера*/
		 		if ($server['Type'][0]['name'] == 'srcds'
						or
					$server['Type'][0]['name'] == 'hlds'
					){

					$config = 'server.cfg';
					$rootPath = 'servers/'.$server['GameTemplate'][0]['name'].'_'.$id;
					$configPath = $server['GameTemplate'][0]['configPath'];
					$delim = 'space';


					 }
		 		else
				if ($server['Type'][0]['name'] == 'cod'){
					if (empty($server['Server']['mod'])
							or
						$server['Server']['mod'] == 'ModWarfare')
						{

							$config = 'server.cfg';
							$configPath = 'main';

						}
						else
						{
							$config = 'modserver.cfg';
							$configPath = 'mods/'.$server['Server']['mod'];
						}
					$rootPath = 'servers/'.$server['GameTemplate'][0]['name'].'_'.$id;
					$delim = 'space';
				}
				else
				if ($server['Type'][0]['name'] == 'ueds')
				{
					if ($server['GameTemplate'][0]['name'] == 'killingfloor'){
						$rootPath   = '.killingfloor';
						$configPath = 'System';
						$config     = 'KillingFloor-'.$id.'.ini';
						$passParamName = 'GamePassword';
						$delim = 'eq';
					}
				}
		 		/* Конец выбора конфига */

		 		if ($server['Type'][0]['name'] == 'srcds'
						or
					$server['Type'][0]['name'] == 'hlds'
						or
					$server['Type'][0]['name'] == 'cod'
						or
					$server['Type'][0]['name'] == 'ueds'
					){

						$data = 'id='.$id .
								'&p='.$paramName .
								'&val='.$paramValue .
								'&desc='.$paramDesc .
								'&conf='.$config .
								'&path='.$rootPath.'/'.$configPath .
								'&a=write' .
								'&d='.$delim;
						$requestStr = '/~configurator/scripts/subscript_read_write_param.py';

						$HttpSocket = new HttpSocket();
						$response = $HttpSocket->get($server['Server']['address'].$requestStr, $data);

				 		$xml = new Xml($response);
				 		$xmlAsArray = $xml->toArray();

						// Прасинг лога и ошибок
				 		$responseMessages = $this->parseXmlResponse($xmlAsArray);
				 		$error = $responseMessages['error'];
				 		$log   = $responseMessages['log'];


				 		/*
				 		 * Для HLDS серверов, пароль сервера необходимо прописывать также и в hltv.cfg
				 		 */
				 		if ($server['Type'][0]['name'] == 'hlds'){
					 		$data = 'id='.$id .
									'&p=serverpassword' .
									'&val='.$paramValue .
									'&desc=Пароль сервера, к которому подключается HLTV'.
									'&conf=hltv.cfg' .
									'&path=.'.
									'&a=write';

					 		$HttpSocket = new HttpSocket();
							$response = $HttpSocket->get($server['Server']['address'].$requestStr, $data);

					 		$xml = new Xml($response);
					 		$xmlAsArray = $xml->toArray();

							// Прасинг лога и ошибок
					 		$responseMessages = $this->parseXmlResponse($xmlAsArray);
					 		$error .= $responseMessages['error'];
					 		$log   .= $responseMessages['log'];
				 		}
				 		 /* Конец записи пароля в HLTV*/

						if (empty($responseMessages['error'])){
							if ($paramDesc == 'None'){
								$this->Session->setFlash('Параметр '.$paramName.' установлен успешно. Перезагрузите сервер.', 'flash_success');
							}
							else
							{
								$this->Session->setFlash($paramDesc.' '.$message.' успешно. Перезагрузите сервер.', 'flash_success');
							}

							if ($back == 'return'){
								return true;
							}

						}
						else
						{
							$error = $error.'Лог выполнения задания:<br/>'.$log;
							$this->Session->setFlash('При попытке изменить параметр '.$this->data['Server']['paramName'].
													 ' возникла ошибка:<br/>'.$error, 'flash_error');

							if ($back == 'return'){
								return false;
							}
						}

					}

		 	}
		 	else
		 	{
		 		$this->Session->setFlash('Вы пытаетесь изменить запрещённый параметр. Ай-ай-ай!', 'flash_error');
		 	}
		 }

		 if ($back == 'redir'){
		 	$this->redirect( array('action' => $redir, $id) );
		 }

	}

	// Самостоятельная установка FPS
	function setFps ( $id = null ) {
		 $this->DarkAuth->requiresAuth();

		 if (is_null($id)){
		 	$id = $this->data['Server']['id'];
		 }

		 // Проверим  - владееет ли пользователь сессии этим сервером?
		 if ($this->checkRights($this->data['Server']['id'])){
		 	if (!empty($this->data))
		 	{
		 		$this->loadModel('ServerClean');
		 		$this->ServerClean->id = $id;
		 		$server = $this->ServerClean->read();

				$slots = $server['ServerClean']['slots'];

				if ($slots > 0 and $slots <= 12){
					$fpsmax = 1000;
				}
				else
				if ($slots > 12 and $slots <= 32){
					$fpsmax = 500;
				}
				else
				{
					$fpsmax = 300;
				}

				// Проверка адекватности введенных слотов
				if ($this->data['Server']['fpsmax'] < 30){
					$this->Session->setFlash('Можно установить не менее 30 FPS.', 'flash_error');
				}
				else
				if ($this->data['Server']['fpsmax'] > $fpsmax){
					$this->Session->setFlash('Можно установить не более '.$fpsmax.' FPS.', 'flash_error');
				}
				else
				{
					if ($this->Server->saveField('fpsmax',intval($this->data['Server']['fpsmax']))){
			 			$this->Session->setFlash('FPS установлен. Перезапустите сервер.', 'flash_success');
			 		}
			 		else
			 		{
		 		 		$this->Session->setFlash('Произошла ошибка при сохранении: '.mysql_error(), 'flash_error');
			 		}
				}



		 	}
		 }

		 $this->redirect(array('action'=>'editStartParams',$this->data['Server']['id']));
	}

	/* Самостоятельная установка tickrate*/
	function setTickrate($id = null, $tickrate = null) {

		$this->DarkAuth->requiresAuth();
		if (!empty($this->data)){
			$id = @$this->data['Server']['id'];
			$tickrate = $this->data['Server']['tickrate'];
		}

		if (in_array($tickrate, array('30', '33', '60', '64', '66', '90', '100', '128'))) // Проверка на правильные значения tickrate
		{
			// Проверим  - владееет ли пользователь сессии этим сервером?
			if ($this->checkRights($id))
			{

				$this->loadModel('ServerTemplate');
				$this->ServerTemplate->id = $id;

				$server = $this->ServerTemplate->read();

				if (in_array($server['GameTemplate']['0']['name'], array('l4d-t100', 'l4d2-t100', 'cssv34', 'csgo-t128'))) // Проверка на игру
				{
					if ($this->Server->saveField('tickrate', intval($tickrate))){
			 			$this->Session->setFlash('Tickrate установлен. Перезапустите сервер.', 'flash_success');
			 		}
			 		else
			 		{
		 		 		$this->Session->setFlash('Произошла ошибка при сохранении: '.mysql_error(), 'flash_error');
			 		}
			 	}
			 	else
			 	{
			 		$this->Session->setFlash('Недопустимая игра. Параметр не изменён.', 'flash_error');
			 	}
			}
		}
		else
		{
			$this->Session->setFlash('Недопустимое значение tickrate. Параметр не изменён.', 'flash_error');
		}

		$this->redirect(array('action'=>'editStartParams',$this->data['Server']['id']));
	}

	/* Самостоятельная смена количества слотов клиентом
	 * При смене слотов, пропорционально меняется срок аренды
	 */
	function setSlots ( $id = null, $return = false) {
		$this->DarkAuth->requiresAuth();

		 if (is_null($id)){
		 	$id = $this->data['Server']['id'];
		 }

		 // Проверим  - владееет ли пользователь сессии этим сервером?
		 if ($this->checkRights($this->data['Server']['id'])){
		 	if (!empty($this->data))
		 	{
		 		$this->loadModel('ServerTemplate');
		 		$this->ServerTemplate->bindModel(array(
											'hasAndBelongsToMany' => array(
																'User' => array()
													)));
		 		$this->ServerTemplate->id = $id;
		 		$server = $this->ServerTemplate->read();

		 		if ($server['GameTemplate'][0]['name'] != 'cod4fixed'){

			 		// Админам можно ставить любое количество слотов
			 		$rights = $this->DarkAuth->getAccessList();	// Права доступа

			 		if ( ( $rights['Admin'] == 1 || $rights['GameAdmin'] == 1 )
			 				or
			 			 (
			 			  $server['GameTemplate'][0]['slots_min'] <= $this->data['Server']['slots']
			 				and
			 			  $server['GameTemplate'][0]['slots_max'] >= $this->data['Server']['slots'])
			 			 ){


				 		// Все рассчеты проводить в unix-time
				 		$currentRent = strtotime($server['ServerTemplate']['payedTill']);

				 		// Установить верхнюю планку FPS
				 		if ($this->data['Server']['slots'] <= 12){
				 			$fpsMax = 1000;
				 		}
				 		else
				 		if ($this->data['Server']['slots'] > 12
				 				and
				 			$this->data['Server']['slots'] <= 32){

				 			$fpsMax = 500;
				 		}
				 		else
				 		{
				 			$fpsMax = 300;
				 		}

				 		if (empty($server['ServerTemplate']['fpsmax'])
				 				or
				 			$server['ServerTemplate']['fpsmax'] > $fpsMax
				 			){

				 			$server['ServerTemplate']['fpsmax'] = $fpsMax;
				 		}

				 		// Если срок аренды уже закончился (ну вдруг), то не менять его,
				 		// иначе пересчитать
				 		if (time() >= $currentRent){
				 			$newRent = $currentRent;
				 		}
				 		else
				 		{
				 			$rentLeft = $currentRent - time();
				 			$newRent  = time() +
				 			            intval(($server['ServerTemplate']['slots']/$this->data['Server']['slots']) * $rentLeft);
				 			$server['ServerTemplate']['payedTill'] = date('Y-m-d H:i:s', $newRent);
				 			$server['ServerTemplate']['slots']     = $this->data['Server']['slots'];
				 			$server['GameTemplate']['id'] = $server['GameTemplate'][0]['id'];

				 			$userId = $server['User'][0]['id'];
				 			$serverId = $server['ServerTemplate']['id'];

				 			// Сохраняем
				 			if ($this->ServerTemplate->save($server)){
				 				$this->Session->setFlash('Новое количество слотов установлено. Также изменился срок аренды. Перезагрузите сервер как можно скорее!', 'flash_error');
				 				$this->TeamServer->logAction('Изменение количества слотов сервера '.strtoupper(@$gameTemplate['GameTemplate']['name']).' #'.$serverId, 'ok', $userId);
				 			}
				 			else
				 			{
				 				$this->Session->setFlash('Произошла ошибка при сохранении: '.mysql_error(), 'flash_error');
				 				$this->TeamServer->logAction('Ошибка при изменении количества слотов сервера '.strtoupper(@$gameTemplate['GameTemplate']['name']).' #'.$serverId, 'error', $userId);
				 			}

				 		}

			 		}
			 		else
			 		{
			 			$this->Session->setFlash('Вы установили некорректное значение слотов:'.$this->data['Server']['slots'].' .'.
			 									 ' Минимум:'.$server['GameTemplate'][0]['slots_min'].
			 									 ' Максимум: '.$server['GameTemplate'][0]['slots_max'], 'flash_error');
			 		}

		 		}// Ошибка при попытке смены слоты для Cod4fixed
		 		else
		 		{
		 			$this->Session->setFlash('Для этой игры невозможно изменить количество слотов.', 'flash_error');
		 		}
		 	}
		 }
		 $this->redirect($this->referer());
	}

	function pluginInstall($id = null, $addon = null, $type = null, $installBy = null) {
		 /**
		  * @$id - server ID
		  * @$addon - что инсталировать
		  * @$type - тип: mod, plugin
		  * @$installBy - инсталлирует скрипт или юзер
		  *
		  **/
		 $this->DarkAuth->requiresAuth();
		 $this->loadModel('ServerMod');
		 $this->loadModel('ServerPluginId');
		 if (!empty($this->params['named']['rediscover'])){
		 	$rediscover = $this->params['named']['rediscover'];
		 }

		 // Проверим  - владееет ли пользователь сессии этим сервером?
		 if ($this->checkRights($id)){

				// Отключить лишние запросы
				$this->Server->unbindModel(array(
													'hasAndBelongsToMany' => array(
																		'Type',
																		'Service',
																		'Order',
																		'RootServer',
																		'VoiceMumbleParam',
																		'RadioShoutcastParam'
															)));
				$this->Server->id = $id;
		 		$this->ServerPluginId->id = $id;
		 		$this->data = $this->Server->read();
		 		$serverPluginsIds = $this->ServerPluginId->read();
		 		$serverId   = $this->data['Server']['id'];
		 		$serverIp   = $this->data['Server']['address'];
		 		$serverPort = $this->data['Server']['port'];
		 		$serverTemplate = $this->data['GameTemplate'][0]['name'];
		 		$userId     = $this->data['User'][0]['id'];

		 		$this->Server->GameTemplate->unbindModel(array(
													'hasAndBelongsToMany' => array(
																		'Type',
																		'Config',
																		'Protocol',
																		'Service'
															)));
		 		$this->Server->GameTemplate->id = $this->data['GameTemplate'][0]['id'];
		 		$gameTemplate = $this->Server->GameTemplate->read();
		 		$this->set('serverId',$id);
		 		//pr($gameTemplate);

 				// Если переменная $plugin существует, то
 				// запускаем процедуру инсталяции
 				if (!empty($addon)){
 					 // инсталяция модов, плагинов
 					 if ($type == 'mod'){
 					 	/*
						 * Необходимо проверить уникальность устанавливаемого мода.
						 * Т.к. при установке новой версии мода, он просто перезаписывается
						 * поверх, а в базе надо убрать ключь ассоциации со старым.
						 * Проверка будет по полю name.
						 */
 					 	$this->Server->Mod->id = $addon;
						$modToInstall = $this->Server->Mod->read();
						$mods = array($addon);
						foreach ( $this->data['Mod'] as $mod ) {
							if ($mod['name'] != $modToInstall['Mod']['name']){
								$mods [] = $mod['id'];
							}
						}
						$server['Mod'] = array('Mod' => $mods);

 					 }
 					 else if ($type == 'plugin'){
 					 	/*
						 * Необходимо проверить уникальность устанавливаемого плагина.
						 * Т.к. при установке новой версии плагина, он просто перезаписывается
						 * поверх, а в базе надо убрать ключь ассоциации со старым.
						 * Проверка будет по полю name.
						 */
 					 	$this->Server->Plugin->id = $addon;
						$pluginToInstall = $this->Server->Plugin->read();
						$plugins = array($addon);
						foreach ( $this->data['Plugin'] as $plugin ) {
							if ($plugin['name'] != $pluginToInstall['Plugin']['name']){
								$plugins[] = $plugin['id'];
							}
						}
						$server['Plugin'] = array('Plugin' => $plugins);
 					 }

 					 $server['Server']['id'] = $id;

 					 if (empty($installBy)){
 					 	/*
 					 	 * Запуск физической установки, если нет ключа InstallBy
						 * Формируем запрос
						 */

				 		$data = "id=".$serverId.
								"&plugin=".$addon.
								"&type=".$type;

				 		$request = "~configurator/scripts/subscript_plugin_install.py?".$data;

				 		$response = $this->TeamServer->webGet($serverIp, 0, $request, "GET");

				 		 // Формируем результат запроса

				 		$var = eregi("<!-- INSTALL RESULT START -->(.*)<!-- INSTALL RESULT END -->", $response, $out);
			 		 	$installResult = trim($out[1]);
			 		 	$var = eregi("<!-- CONFIG RESULT START -->(.*)<!-- CONFIG RESULT END -->", $response, $out);
			 		 	$configResult = trim($out[1]);

			 		 	if ($installResult == 'error'){
			 		 		$this->Session->setFlash('Ошибка при установке плагина/мода. Обратитесь в техподдержку.', 'flash_error');
			 		 		$this->redirect(array('action'=>'pluginInstall',$id));
			 		 	}

 					 }

 					 if (@$installResult == 'success' or $installBy == 'user'){

						 if($this->Server->saveAll($server)){


							if (@$configResult == 'success'){
			 		 			$this->Session->setFlash('Установлен и сконфигурирован успешно. ' .
			 		 									 'Не забудьте перезагрузить сервер для ' .
			 		 									 'активации плагина/мода.', 'flash_success');
			 		 		}
			 		 		else if (@$configResult == 'error'){
			 		 			$this->Session->setFlash('Установлен успешно, но при конфигурации ' .
			 		 									 'возникла ошибка. Попробуйте сконфигурировать ' .
			 		 									 'вручную или обратитесь в техподдержку. Вы можете ' .
			 		 									 'перезагрузить сервер для активации плагина/мода. ', 'flash_error');
			 		 		}

							if ($installBy == 'user'){
								$this->Session->setFlash('Мод помечен, как установленный вручную. ' .
														 'Теперь можете устанавливать плагины. ' .
														 'Обратите внимание, что плагины привязаны к ' .
														 'определённому моду!', 'flash_success');
							}

							$this->redirect(array('action'=>'pluginInstall',$id));
	 					 }
 					 }
 				}// Инсталляция модов/плагинов - конец

 				// Если мод уже установлен, то выводим список
 				// плагинов и проверяем их состояние - установлен или нет
 				else {


					// Получим список плагинов, относящихся к шаблону
					if (!empty($gameTemplate['Plugin'])){
						foreach ( $gameTemplate['Plugin'] as $plugin ) {
	   						$gameTemplatePlugins[$plugin['id']] = $plugin;
	   						$gameTemplatePluginsIds[] = $plugin['id'];
						}
					}

	 				if (!empty($this->data['Mod'])){

						// Получим список плагинов, относящихся к моду
						foreach ( $this->data['Mod'] as $mod ) {
							$modsList[] = $mod['id'];

						}

						$mods = $this->Server->Mod->find('all',
												array(
														'conditions' => array(
																			   'id' => $modsList
																			  )
															 )
												);

						/*
						 * Тут хитрая комбинация. Для того, чтобы вычислить
						 * пересекающийся массив ниже, мне необходимо,
						 * чтобы ключи были одниковые, а самый простой путь
						 * это сделать - сделать ключи по ID плагинов.
						 */

						foreach ( $mods as $mod ) {

							foreach ( $mod['Plugin'] as $plugin ) {
	       						$modPlugins[$plugin['id']] = $plugin;
							}

						}

						// Теперь создадим массив из пересекающихся
						// значений палагинов у мода и шаблона
						// (моду могут принадлежать плагины к разным шаблонам,
						// а у шаблона могут быть плагины разных модов)

						if (!empty($modPlugins) && !empty($gameTemplatePlugins)){

							$pluginsList = array_intersect_key($modPlugins,$gameTemplatePlugins);

							// Ищем уже установленные плагины и прописываем соотв ключ в массив
							$j = 0;
							// Список ID плагинов для запроса списка тэгов
							foreach ( $pluginsList as $plugin ) {
								$pluginsIds[] = $plugin['id'];
							}
							// Теперь необходимо получить список тэгов для плагинов
			 				$this->Server->Plugin->unbindModel(array(
														'hasAndBelongsToMany' => array('Config' )));

							$pluginsWithTags = $this->Server->Plugin->find('all', array(
																						'conditions' => array (
																												'id' => $pluginsIds)));
							$resultPluginsNamesList = array();
			 				foreach ( $pluginsWithTags as $plugin ) {
			 						$resultPluginsList[$j] = $plugin;
			 						$resultPluginsNamesList[] = $plugin['Plugin']['longname'];
									if ($this->ServerPluginId->in_multiarray($plugin['Plugin']['id'],$serverPluginsIds['Plugin'])

										){
										$resultPluginsList[$j]['Plugin']['installed'] = 1;
									}
									$j++;

							}

							if (!empty($resultPluginsList))
							{
								sort($resultPluginsNamesList);

								$sortedPluginsNames = array_flip($resultPluginsNamesList);
								$sortedPlugins = array();

								foreach ($resultPluginsList as $plugin) {
									$key = $sortedPluginsNames[$plugin['Plugin']['longname']];
									$sortedPlugins[$key] = $plugin;

								}

								ksort($sortedPlugins);

								$this->set('pluginsList',@$sortedPlugins);
							}


						}

		 				$this->set('installedMod',$modsList);
		 				$this->set('installedPlugins',$this->data['Plugin']);


	 					}

	 					// Если же мод не установлен, предлагаем его поставить
 						if (!empty($gameTemplate['Mod'])){

 							foreach ( $gameTemplate['Mod'] as $mod ) {
								$modsList[] = $mod['id'];

							}

							$this->Server->Mod->unbindModel(array(
														'hasAndBelongsToMany' => array('Config' )));

							$this->Server->Mod->bindModel(array(
														'hasAndBelongsToMany' => array(
																			'Plugin' => array( 'fields' => 'id, longname, version',
																								'conditions' => array (
																												'id' => @$gameTemplatePluginsIds
																												)
																							)
														)));

							$mods = $this->Server->Mod->find('all',
													array(
															'conditions' => array(
																				   'id' => $modsList
																				  )
																 )
													);

							$this->set('modsList',$mods);

 						}
		 				else
		 				{
	 						// Если нет привязки мода к шаблону, выводим сообщение
							$this->Session->setFlash("Плагины для данного типа серверов недоступны. Если вы считаете это неправильным - сообщите нам об этом!", 'flash_error');
	 					}

 				}
			}//check rights

	}

	function pluginResync($id = null){


		if ($this->checkRights($id)){
				$this->loadModel('Mod');
				$this->loadModel('GameTemplatePlugin');
				$this->loadModel('ServerPlugin');
		 		$this->Server->id = $id;
		 		$server = $this->Server->read();

		 		if (!empty($server['Mod'])){
			 		// Получим список плагинов, относящихся к моду
			 		foreach ( $server['Mod'] as $serverMod ) {
       					$installedModsIds[] = $serverMod['id'];
					}
					$this->Server->Mod->unbindModel(array(
														'hasAndBelongsToMany' => array('Config' )));

					$this->Server->Mod->bindModel(array(
														'hasAndBelongsToMany' => array(
																			'Plugin' => array( 'fields' => 'id, name, longname, version')
														)));

					// Запрос модов из списка установленных
					$mods = $this->Server->Mod->find('all', array( 'conditions' => array ('id' => $installedModsIds)));


					$this->GameTemplatePlugin->bindModel(array(
														'hasAndBelongsToMany' => array(
																			'Plugin' => array( 'fields' => 'id, name, longname, version')
														)));

					$this->GameTemplatePlugin->id = $server['GameTemplate'][0]['id'];
					$gameTemplate = $this->GameTemplatePlugin->read();

					/*
					 * Тут хитрая комбинация. Для того, чтобы вычислить
					 * пересекающийся массив ниже, мне необходимо,
					 * чтобы ключи были одниковые, а самый простой путь
					 * это сделать - сделать ключи по ID плагинов.
					 */
					foreach ( $mods as $mod ) {
						foreach ( $mod['Plugin'] as $plugin ) {
							$modPlugins[$plugin['id']] = $plugin;
						}
					}
					// Получим список плагинов, относящихся к шаблону
					foreach ( $gameTemplate['Plugin'] as $plugin ) {
						$gameTemplatePlugins[$plugin['id']] = $plugin;
					}

					// Теперь создадим массив из пересекающихся
					// значений палагинов у мода и шаблона
					// (моду могут принадлежать плагины к разным шаблонам,
					// а у шаблона могут быть плагины разных модов)

					$pluginsList = array_intersect_key($modPlugins,$gameTemplatePlugins);

					$pluginsReq = '';
					foreach ( $pluginsList as $pluginId ) {
       					$pluginsReq .= $pluginId['id'].':';
					}

			 		$data = "id=".$id.
							"&plugins=".$pluginsReq.
							"&action=check";

			 		$requestStr = "/~configurator/scripts/subscript_plugin_check_del.py";


			 		$HttpSocket = new HttpSocket();
			 		$response = $HttpSocket->get($server['Server']['address'].$requestStr, $data);

			 		$xml = new Xml($response);

			 		$xmlAsArray = $xml->toArray();
			 		$plugins = array();

			 		if (!empty($xmlAsArray['Response']['Plugin'])){
			 			// Если больше одного плагина, перебираем их
			 			if (!empty($xmlAsArray['Response']['Plugin'][0])){
				 			foreach ( $xmlAsArray['Response']['Plugin'] as $plugin ) {

				 				if (@$plugin['result'] == 'installed'){
				 					$plugins[] = $plugin['id'];
				 				}

							}
			 			}
			 			else // Иначе просто присваиваем
			 			{
			 				if ($xmlAsArray['Response']['Plugin']['result'] == 'installed'){
			 					$plugins[] = $xmlAsArray['Response']['Plugin']['id'];
			 				}
			 			}


						$serverResync['Plugin'] = array('Plugin' => $plugins);
						$serverResync['ServerPlugin']['id'] = $id;

			 		}

			 		if (!empty($serverResync)){

				 		if ($this->ServerPlugin->save($serverResync)){
				 			$this->Session->setFlash('Синхронизация проведена успешно. ' .
				 					'Проверьте итоговый список и если тут указано, что плагин не установлен, ' .
				 					'то либо ваша версия сильно отличается от протестированной нами, либо ' .
				 					'установлена с ошибками. В этом случае рекомендуем установить нашу протестированную ' .
				 					'версию.', 'flash_success');
				 		}
				 		else
				 		{
				 			$this->Session->setFlash('Возникла ошибка при сохранении данных синхронизации.' .
				 					' Попробуйте повторить позже.', 'flash_error');
				 		}
			 		}
			 		else
			 		{
			 			$this->Session->setFlash('Синхронизация проведена успешно. ' .
				 					'К сожалению, не обнаружено ни одного известного нам плагина. ' .
				 					'Если это неверно, ' .
				 					'то либо ваша версия сильно отличается от протестированной нами, либо ' .
				 					'установлена с ошибками. В этом случае рекомендуем установить нашу протестированную ' .
				 					'версию.', 'flash_success');
			 		}



		 		}
		 		else
		 		{
		 			$this->Session->setFlash('На сервере не установлен мод. Синхронизация невозможна.', 'flash_error');
		 		}

		}
		$this->redirect(array('action'=>'pluginInstall',$id));

	}

	function pluginDelete($id = null, $pluginId = null){

		App::import('Xml');
		App::import('Core', 'HttpSocket');

		if ($this->checkRights($id)){
			$this->loadModel('ServerPlugin');
			$this->loadModel('Plugin');
		 	$this->ServerPlugin->id = $id;
		 	$server = $this->ServerPlugin->read();

		 	if (!empty($server['Plugin'])){

 				$data = "id=".$id.
					"&plugins=".$pluginId.
					"&action=delete";

		 		$requestStr = "/~configurator/scripts/subscript_plugin_check_del.py";


		 		$HttpSocket = new HttpSocket();
		 		$response = $HttpSocket->get($server['ServerPlugin']['address'].$requestStr, $data);

		 		$xml = new Xml($response);

		 		$xmlAsArray = $xml->toArray();
		 		//pr($xmlAsArray);
		 		$plugins = array();
		 		if ( !empty($xmlAsArray['Response']['Plugin'])
		 				and
		 			  $xmlAsArray['Response']['Plugin']['result'] == 'success'
		 			)
		 			{
		 				foreach ( $server['Plugin'] as $plugin ) {

				 			if ($plugin['id'] != $pluginId){
				 				$plugins[] = $plugin['id'];
				 			}
		 				}

						$serverResync['Plugin'] = array('Plugin' => $plugins);
						$serverResync['ServerPlugin']['id'] = $id;

				 		if ($this->ServerPlugin->save($serverResync)){
				 			$this->Session->setFlash('Плагин удалён успешно.', 'flash_success');
				 		}
				 		else
				 		{
				 			$this->Session->setFlash('Возникла ошибка при сохранении данных.', 'flash_error');
				 		}

		 			}
		 			else
		 			{
		 				$this->Session->setFlash('Возникла ошибка удаления плагина.', 'flash_error');
		 			}


		 	}

		}

		$this->redirect(array('action'=>'pluginInstall',$id));

	}

	/* Установка и просмотр установленных карт
	 *
	 * - Получить список установленных карт на сервере
	 * - Получить список доступных карт
	 * - Совместить список
	 *
	 */
	function mapInstall ( $id = null, $mapId = null, $mapType = 'installed', $action = 'install', $out = 'html', $output = null ) {
		$this->DarkAuth->requiresAuth();

//		$this->Session->setFlash('Установка карт еще в тестовом режиме!!! Большинство функций не работают! ' .
//								 'Пожалуйста, не пишите в техподдержку, пока мы не анонсируем полностью ' .
//								 'рабочий вариант.', 'flash_error');


		if (@$id == null){
		 	$id = $this->data['Server']['id'];
		}

		if ($this->checkRights($id)){

			$this->set('id', $id);
			$this->set('mapTypeActive', $mapType);

			if (!is_null($output))
			{
				$this->Session->write('mapOutput', $output);
			}
			else
			if (is_null($output) and $this->Session->check('mapOutput'))
			{
				$output = $this->Session->read('mapOutput');
			}
			else
			if (is_null($output) and !$this->Session->check('mapOutput'))
			{
				$output = 'list';
			}

			$this->set('output', $output);

			// Если нет mapId, то пытаемся получить список установленных карт
			if (is_null($mapId) or $mapId == 'all'){
				// Список карт получить из setMap
				$this->setMap( $id );
				$server = $this->data;

				unset($this->data);

			 	$mapsTurnedOn = false;

			 	switch ( strtolower($server['Type'][0]['name']) ) {
					case 'hlds':
					case 'srcds':

						// Получить список включенных карт
						if (strtolower($server['Type'][0]['name']) == 'hlds'){
							// Для HL1 использовать только mapcycle
							$mapsTurnedOn = $this->editConfigCommon($id, 72, 'read');
						}
						else
						{
							$mapsTurnedOn = $this->editConfigCommon($id, 71, 'read');
						}

						if ($mapsTurnedOn !== false){
							$mapsTurnedOn = preg_split('/\n/',$mapsTurnedOn);
						}

						// Для лефты заблокировать установку карт
						if ($server['GameTemplate'][0]['name'] == 'l4d' or $server['GameTemplate'][0]['name'] == 'l4d2'){
							$this->Session->setFlash('Установка карт для этой игры пока недоступна.', 'flash_error');
							$this->render();
							break;
						}

						// Подготовим массив и внесем параметры по-умолчанию,
						// которые позже дополним
						foreach ( $server['Server']['maps'] as $map ) {
       						$server['Server']['maps'][$map] = array ('name' => $map,
       																 'installed' => true,  // карта на сервере установлена
       																 'canDelete' => false, // по-умолчанию, мы не можем её удалить
       																 'on' => true		   // по-усолчанию, считаем, что карта включена
       																 );
       						// Проверить включена ли карта
       						if ($mapsTurnedOn !== false){
       							if (!in_array($map, $mapsTurnedOn)){
       								$server['Server']['maps'][$map]['on'] = false;
       							}
       						}
						}


						// Если нет $mapType, то выводим список установленных карт
						// Сначала получим список известных нам карт, привязанных к шаблону

						// Нефиг запрашивать лишнюю информацию из базы
						$this->Server->GameTemplate->unbindModel( array( 'hasAndBelongsToMany' => array(
																					'Mod',
																					'Plugin',
																					'Config',
																					'Service',
																					'Server'
																		)));
						$this->Server->GameTemplate->bindModel( array( 'hasAndBelongsToMany' => array(
																					'Map' => array(
																									'fields' => 'id, name, longname, desc, official, map_type_id'
																								   )
																							   )));

						$this->Server->GameTemplate->id = $server['GameTemplate'][0]['id'];
						$gameTemplate = $this->Server->GameTemplate->read();

						// Если указан тип карт, то получить сначала список привязанных к нему карт
						// Потом скоррелировать его с шаблоном

						if (!is_null($mapType) and $mapType != 'installed'){

							// array_intersect в нашем случае работает через попень, т.к.
							// массивы многомерные и с кучей переносов строк и текста
							// Поэтому сделаю сравнение по ID карты
							if (!empty($gameTemplate['Map'])){
								foreach ( $gameTemplate['Map'] as $map ) {
			       					$gameTemplateMaps[] = $map['id'];
								}
							}

							$this->loadModel('MapType');

							$this->MapType->unbindModel( array ( 'hasMany'  => array ( 'Map' )) );
							$this->MapType->bindModel( array ( 'hasMany' => array(
																					'Map' => array(
																									'fields' => 'id, name, longname, desc, official'
																								   )
																				  )));

							$mapsByType = $this->MapType->find('all', array ( 'conditions' => array ( 'name' => $mapType)));

							$mapList = array();
							foreach ( $mapsByType[0]['Map'] as $map){
								if (in_array($map['id'], $gameTemplateMaps)){
									$mapList[$map['name']] = array ( 'name' => $map['name']);
									$mapList[$map['name']]['id'] = $map['id'];
									$mapList[$map['name']]['longname'] = $map['longname'];
									$mapList[$map['name']]['desc'] = $map['desc'];
									$mapList[$map['name']]['official'] = ((bool) $map['official']);

									if (array_key_exists($map['name'], $server['Server']['maps'])){
										$mapList[$map['name']]['installed'] = true;

										// Т.к. карта нам известна, мы можем её удалить
	       								$mapList[$map['name']]['canDelete'] = true;

	       								// Ключ, включена ли карта
	       								if (!empty($server['Server']['maps'][$map['name']]['on'])){
	       									$mapList[$map['name']]['on'] = true;
	       								}
	       								else
	       								{
	       									$mapList[$map['name']]['on'] = false;
	       								}

									}
									else
									{
										$mapList[$map['name']]['installed'] = false;
										$mapList[$map['name']]['canDelete'] = true;
										$mapList[$map['name']]['on'] = false;
									}

									// Проверить на наличие изображения карты
	       							if (file_exists(WWW_ROOT.'/img/gameMaps/'.$map['id'].'.jpg')){
	       								$mapList[$map['name']] ['image'] = $map['id'];
	       							}
	       							else
	       							{
	       								$mapList[$map['name']] ['image'] = NULL;
	       							}
								}

							}
							asort($mapList);
							$this->set('maps', $mapList);
						}
						else
						{
							foreach ( $gameTemplate['Map'] as $gameTemplateMap ) {
	       						if (array_key_exists($gameTemplateMap['name'], $server['Server']['maps'])){
	       							$server['Server']['maps'][$gameTemplateMap['name']]['id']       = $gameTemplateMap['id'];
	       							$server['Server']['maps'][$gameTemplateMap['name']]['longname'] = $gameTemplateMap['longname'];
	       							$server['Server']['maps'][$gameTemplateMap['name']]['desc']     = $gameTemplateMap['desc'];
	       							$server['Server']['maps'][$gameTemplateMap['name']]['official'] = ((bool) $gameTemplateMap['official']);

	       							// Т.к. карта нам известна, мы можем её удалить
	       							$server['Server']['maps'][$gameTemplateMap['name']]['canDelete'] = true;

	       							// Проверить на наличие изображения карты
	       							if (file_exists(WWW_ROOT.'/img/gameMaps/'.$gameTemplateMap['id'].'.jpg')){
	       								$server['Server']['maps'][$gameTemplateMap['name']] ['image'] = $gameTemplateMap['id'];
	       							}
	       							else
	       							{
	       								$server['Server']['maps'][$gameTemplateMap['name']] ['image'] = NULL;
	       							}

	       						}
							}
							asort($server['Server']['maps']);
							$this->set('maps', $server['Server']['maps']);

						}

						// Список типов карт надо формировать по картам, привязанным на шаблон
						// Сначала составить список, для отправки запроса в БД
						$gameTemplateMaps = array();
						foreach ( $gameTemplate['Map'] as $map ) {
							$gameTemplateMapIds[] = $map['id'];
						}

						$this->loadModel('Map');
						$this->Map->unbindModel( array( 'hasAndBelongsToMany' => array(
																					'GameTemplate'
																		)));

						$gameTemplateMaps = $this->Map->find('all', array ( 'conditions' => array ( 'Map.id' => $gameTemplateMapIds)));

						$mapTypes = array ();
						foreach ( $gameTemplateMaps as $gameTemplateMap ) {
       						$mapTypes['MapType'][] = $gameTemplateMap['MapType'];
						}


						$mapTypesByName = array();
						foreach ( $mapTypes['MapType'] as $mapType ) {
       						$mapTypesByName[$mapType['name']] = $mapType['longname'];
						}

						$this->set('mapTypes', $mapTypesByName);

						break;

					case 'cod':

					default:
						$this->Session->setFlash('Установка карт для данного типа серверов невозможна. Если вы считаете это неправильным, сообщите нам.', 'flash_error');
						$this->render();
						break;

				}
			}
			// Иначе запускаем процедуру установки
			else
			{
				$this->loadModel('ServerClean');
				$this->ServerClean->id = $id;
				$server = $this->ServerClean->read();

			 	$data = "id=".$id.
			 			"&a=".$action.
			 			"&map=".$mapId;

		 		$requestStr = "/~configurator/scripts/subscript_map_install_delete.py";

		 		$HttpSocket = new HttpSocket();
		 		$response = $HttpSocket->get($server['ServerClean']['address'].$requestStr, $data);

		 		$xml = new Xml($response);
		 		$xmlAsArray = $xml->toArray();

				// Прасинг лога и ошибок
				$responseMessages = $this->parseXmlResponse($xmlAsArray);

				switch ( $action ) {
						case 'install':
							$actionWord = 'установлена';
							$actionWordErr = 'установке';
							Cache::delete('maps_'.$id);
							break;

						case 'delete':
							$actionWord = 'удалена';
							$actionWordErr = 'удалении';
							Cache::delete('maps_'.$id);
							break;

						case 'turnOn':
							$actionWord = 'включена';
							$actionWordErr = 'включении';
							break;

						case 'turnOff':
							$actionWord = 'отключена';
							$actionWordErr = 'отключении';
							break;

						default:
							break;
					}

				if (!empty($responseMessages['error'])){

					$this->Session->setFlash('Возникла ошибка при '.$actionWordErr.' карты: '.$responseMessages['error'].
											 'Лог выполнения задания: '.$responseMessages['log'], 'flash_error');

					$this->set('result', 'error');

				}
				else
				{
					$this->Session->setFlash('Карта '.$actionWord.' успешно. Перезагрузите сервер для применения изменений.', 'flash_success');
					$this->set('result', 'ok');
				}

				if ($out == 'html'){
					$this->redirect(array('action' => 'mapInstall', $id, 'all', $mapType));
				}
				else
				{
					$this->layout = 'ajax';
					$this->render('result_json');
				}


			}

		}

	}

	function editParams ($id = null){
		$this->DarkAuth->requiresAuth();

		if (@$id == null){
		 	$id = $this->data['Server']['id'];
		}

		if (intval($id) > 0){ // $id должен быть цифрой и больше нуля
			$this->loadModel('GameTemplate');
			$this->Server->id = $id;
			$server = $this->Server->read();
			// В зависимости от типа и шаблона, переадресуем на соответвующий контроллер
			// Да, можно это делать из функции, что привязана к типу, но
			// тогда будет сгененрирован дополнительный редирект 304,
			// что не есть хорошо.
			switch ( strtolower($server['Type'][0]['name']) ) {
				case 'hlds':
				case 'srcds':
				case 'cod':
					// Эти типы во многом схожи, в частности в редактировании параметров
					$redirTo = 'editParamsSrcds';

					break;

				case 'radio':
				case 'voice':
					$redirTo = 'editParams'.
								ucfirst(strtolower($server['Type'][0]['name'])).
								ucfirst(strtolower($server['GameTemplate'][0]['name']));
					// В результате будет сгенерирован редирект вроде
					// editParamsVoiceMumble

					break;

				default:
					$redirTo = 'editParams'.ucfirst(strtolower($server['Type'][0]['name'])); // Сделать первый символ в верхнем регистре
					break;

			}

			$this->redirect(array('action'=>$redirTo,$id));
		}
		else
		{
			$this->Session->setFlash('Некорректный Server ID.', 'flash_error');
			$this->redirect(array('action'=>'result'));
		}

	}

	function editParamsSrcds ($id = null, $addonType = null, $addonId = null){

		$this->DarkAuth->requiresAuth();


		if ($this->checkRights($id)){

			$this->Server->User->id = $this->DarkAuth->getUserId();

			$this->loadModel('ServerModPlugin');

			// Нефиг запрашивать лишнюю информацию из базы
			$this->Server->unbindModel(array(
											'hasAndBelongsToMany' => array(
																'Order',
																'Mod',
																'Plugin',
																'Config',
																'RootServer',
																'VoiceMumbleParam',
																'RadioShoutcastParam'
													)));
		 	$this->Server->id = $id;
		 	$this->data = $this->Server->read();

			$this->Server->GameTemplate->id = $this->data['GameTemplate'][0]['id'];
			$template = $this->Server->GameTemplate->read();

			$this->ServerModPlugin->bindModel(array(
											'hasAndBelongsToMany' => array(
																'Service' => array()
													)));
			$server = $this->ServerModPlugin->find('first',
											array(
													'recursive' => '2',
													'conditions' => array(
																		'id' => $id
																		 )
												  ));

	       	/* Составить список конфигов
	       	 * - сервера
	       	 * - модов
	       	 * - плагинов
	       	 */
	       	if (!empty($template['Config'])
	       			and
	       			(
	       				!$addonType
	       					or
	       				 $addonType == 'server'
	       			)){

	       			$this->set('configsOwner','server');
		       		foreach ( $template['Config'] as $config ) {
		       			$config['type'] = 'server';
		       			$configs[] = $config;
					}
	       	}
	       	else
	       	if (empty($template['Config']) && !$addonType){
	       		$this->set('configsOwner','server');
	       	}

			if ($addonType and $addonId) {

				if ( $addonType == 'mod' )
				{
					$this->Server->Mod->id = $addonId;
					$mod = $this->Server->Mod->read();
					$this->set('configsOwner',$mod['Mod']['name']);
					foreach ( $mod['Config'] as $config ) {
		       			$config['type'] = $mod['Mod']['name'];
		       			$configs[] = $config;
					}

				}
				// Тут составить список конфигов для мода,
				// установленного самим клиентом, но в соотв
				// с нашими правилами
				else if ( $addonType == 'userMod' )
				{
					if (@$template['Type'][0]['name'] == 'cod'){
						// Конфиг мода расположен в директории
						// самого мода и имеет имя modserver.cfg
						// Переменной addonId передаётся имя мода
						$addonId = trim($addonId,'.,/\\');
						$this->set('configsOwner',$addonId);
						$config['id'] = $addonId;
						$config['name'] = 'modserver.cfg';
						$config['path'] = 'mods/'.$addonId;
						$config['type'] = 'userMod';
						$configs[] = $config;
					}


				}
				else if ( $addonType == 'plugin' ){
					$this->Server->Plugin->id = $addonId;
					$plugin = $this->Server->Plugin->read();
					$this->set('configsOwner',$plugin['Plugin']['name']);
					foreach ( $plugin['Config'] as $config ) {
		       			$config['type'] = @$template['Type'][0]['name'] ;
		       			$configs[] = $config;
					}

				}
			}

			$this->set('configs',@$configs);
			$this->set('server',$server);

			/* Т.к. различия в редакторе конфигурации лишь косметические,
			 * не буду плодить функций, а ниже определю, как вид рендерить.
			 * Не знаю, насколько это неизящно, зато практично. Хоть и костыль.
			 */

			 if (@$template['Type'][0]['name'] == 'cod'){
			 	// Пулучить список установленных модов
			 	$data = "id=".$id;
		 		$requestStr = "/~configurator/scripts/subscript_cod_mod_list.py";

		 		$HttpSocket = new HttpSocket();
		 		$response = $HttpSocket->get($server['ServerModPlugin']['address'].$requestStr, $data);

		 		$xml = new Xml($response);
		 		$xmlAsArray = $xml->toArray();

				// Прасинг лога и ошибок
				$responseMessages = $this->parseXmlResponse($xmlAsArray);
				$error = $responseMessages['error'];

		 		// Парсинг списка модов
		 		$modsList = array();
		 		if (!empty($xmlAsArray['Response']['List']['Dir'])){
		 			foreach ( $xmlAsArray['Response']['List']['Dir'] as $mod ) {
       					$modsList[$mod] = $mod;
					}
		 		}
		 		else if (!empty($xmlAsArray['Response']['List']['dir']))
		 		{
		 			$modsList[0] = $xmlAsArray['Response']['List']['dir'];
		 		}
		 		else
		 		{
		 			if (!empty($server['ServerModPlugin']['mod'])){
		 				$error .= 'Не обнаружен мод сервера, хотя в строке запуска он установлен! Переинициализируйте сервер, либо загрузите нужный мод и установите его модом по умолчанию!';
		 			}
		 			else
		 			{
		 				$error .= 'Не обнаружен мод сервера!';
		 			}

		 		}

		 		// Вывод ошибки
		 		if (@$error != ''){
		 			$this->Session->setFlash('Возникла ошибка при получении списка установленных модов: '.$error, 'flash_error');
		 		}
		 		$this->set('mods', $modsList);
			 	$this->render('edit_params_cod');
			 }
			 else
			 {
			 	$this->render('edit_params');
			 }

		}

	}

	function editParamsVoiceMumble ($id = null){
		 $this->DarkAuth->requiresAuth();

		 if (@$id == null){
		 	$id = $this->data['Server']['id'];
		 }

		 if ($this->checkRights($id)){

			 /*
			  * Сначала проверить принадлежность сервера пользователю
			  */

			if ($this->checkRights($id)){

				$this->loadModel('VoiceMumbleParam');

				if (empty($this->data)){
					$this->Server->id = $id;
					$this->data = $this->Server->read();
				}
				else
				{
					// Сначала надо обнулить опасные поля
					$this->data['Server']['slots'] = '';
					$this->data['Server']['payedTill'] = '';

					// Теперь сохранить изменения параметров
					$this->Server->id = $this->data['Server']['id'];
					$server = $this->Server->read();
					$this->VoiceMumbleParam->id = $server['VoiceMumbleParam'][0]['id'];
					$mumbleParams['VoiceMumbleParam'] = $this->data['VoiceMumbleParam'][0];
					if ($this->VoiceMumbleParam->save($mumbleParams)){
						// Теперь надо сгенерировать новый конфиг и передать его скрипту.
						$newParams =  $this->VoiceMumbleParam->read();
						$newConfig =
'
# How many login attempts do we tolerate from one IP
# inside a given timeframe before we ban the connection?
# Note that this is global (shared between all virtual servers), and that
# it counts both successfull and unsuccessfull connection attempts.
# Set either Attempts or Timeframe to 0 to disable.
defaultchannel='.$newParams['VoiceMumbleParam']['defaultchannel'].'
autobanAttempts='.$newParams['VoiceMumbleParam']['autobanAttempts'].'
autobanTimeframe='.$newParams['VoiceMumbleParam']['autobanTimeframe'].'
autobanTime='.$newParams['VoiceMumbleParam']['autobanTime'].'
bonjour=false

# Murmur default to logging to murmur.log. If you leave this blank,
# murmur will log to the console (linux) or through message boxes (win32).
logfile=/home/client'.$server['User'][0]['id'].'/public_html/output/mumble_'.$server['Server']['id'].'.log

# If set, murmur will write its process ID to this file.
pidfile=/home/pid/client'.$server['User'][0]['id'].'/mumble_'.$server['Server']['id'].'.pid

# The below will be used as defaults for new configured servers.
# If you are just running one server (the default), it is easier to
# configure it here than through D-Bus or Ice.
#
# Welcome message sent to clients when they connect
welcometext="'.addslashes($newParams['VoiceMumbleParam']['welcometext']).'"

# Port to bind TCP and UDP sockets to
port='.$server['Server']['port'].'

# Specific IP or hostname to bind to.
# If this is left blank (default), murmur will bind to all available addresses.
host='.$server['Server']['address'].'

# Password to join server
serverpassword='.$newParams['VoiceMumbleParam']['serverpassword'].'

# Maximum bandwidth (in bits per second) clients are allowed
# to send speech at.
bandwidth='.$newParams['VoiceMumbleParam']['bandwidth'].'

# Maximum number of concurrent clients allowed.
users='.$server['Server']['slots'].'

# Regular expression used to validate channel names
# (note that you have to escape backslashes with \ )
#channelname=[ \\-=\\w\\#\\[\\]\\{\\}\\(\\)\\@\\|]+

# Regular expression used to validate user names
# (note that you have to escape backslashes with \ )
#username=[-=\\w\\[\\]\\{\\}\\(\\)\\@\\|\\.]+

# Maximum length of text messages in characters. 0 for no limit.
textmessagelength='.$newParams['VoiceMumbleParam']['textmessagelength'].'

# Maximum length of text messages in characters, with image data. 0 for no limit.
imagemessagelength='.$newParams['VoiceMumbleParam']['imagemessagelength'].'

# Allow clients to use HTML in messages, user comments and channel descriptions?
allowhtml='.$newParams['VoiceMumbleParam']['allowhtml'].'

# Murmur retains the per-server log entries in an internal database which
# allows it to be accessed over D-Bus/ICE.
# How many days should such entries be kept?
logdays='.$newParams['VoiceMumbleParam']['logdays'].'

# To enable public server registration, the serverpassword must be blank, and
# this must all be filled out.
# The password here is used to create a registry for the server name; subsequent
# updates will need the same password. Do not lose your password.
# The URL is your own website, and only set the registerHostname for static IP
# addresses.
#
registerName="'.addslashes($newParams['VoiceMumbleParam']['registerName']).'"
registerPassword='.$newParams['VoiceMumbleParam']['registerPassword'].'
registerUrl='.$newParams['VoiceMumbleParam']['registerUrl'].'
registerHostname='.$newParams['VoiceMumbleParam']['registerHostname'].'

# If you have a proper SSL certificate, you can provide the filenames here.
sslCert='.$newParams['VoiceMumbleParam']['sslCert'].'
sslKey='.$newParams['VoiceMumbleParam']['sslKey'].'

# If this options is enabled, only clients which have a certificate are allowed
# to connect.
certrequired='.$newParams['VoiceMumbleParam']['certrequired'].'

# You can configure any of the configuration options for Ice here. We recommend
# leave the defaults as they are.
# Please note that this section has to be last in the configuration file.
#
[Ice]
Ice.Warn.UnknownProperties=1
Ice.MessageSizeMax=65536

';

				 		$serverId   = $server['Server']['id'];
				 		$serverName = $server['GameTemplate'][0]['name'];
				 		$userId     = $server['User'][0]['id'];

				 		$iniPath = "/home/client".$userId."/servers/".$serverName."_".$serverId."/murmur.ini";

						 // Совершаем запрос и форматируем вывод

					    $data = "action=write".
								"&cfgPath=".$iniPath.
								"&cfgText=".urlencode($newConfig);

						$requestStr = '/~configurator/scripts/write_user_configs.py';

						$HttpSocket = new HttpSocket();
						$response = $HttpSocket->get($server['Server']['address'].$requestStr, $data);


			 		 	$var = eregi("<!-- RESULT START -->(.*)<!-- RESULT END -->", $response, $out);
			 		 	$responsecontent = trim($out[1]);
			 		 	if ($responsecontent == "success"){
			 		 		$this->Session->setFlash('Конфиг сохранён. Перезапустите сервер.', 'flash_success');
			 		 		$this->redirect(array('action'=>'result',$id));
			 		 	}
			 		 	else{
			 		 		$this->Session->setFlash('Произошла ошибка: -'.$responsecontent.'-', 'flash_error');
			 		 		$this->redirect(array('action'=>'result',$id));
			 		 	}

						$this->Session->setFlash('Параметры сохранены, перегрузите сервер.', 'flash_success');
						$this->redirect(array('action'=>'result'));
					}
					else
					{
						$this->set('errors', $this->VoiceMumbleParam->invalidFields());
						$this->Session->setFlash('Возникла ошибка при сохранении параметров.'.mysql_error(), 'flash_error');
					}

				}
			}

		}

	}

	function editParamsRadioShoutcast ($id = null) {
		$this->DarkAuth->requiresAuth();
		if (@$id == null){
		 	$id = $this->data['Server']['id'];
		 }

		 /*
		  * Сначала проверить принадлежность сервера пользователю
		  */

		if ($this->checkRights($id)){

			$this->loadModel('RadioShoutcastParam');

			if (empty($this->data)){
				$this->Server->id = $id;
				$this->data = $this->Server->read();
			}
			else
			{
				// Сначала надо обнулить опасные поля
				$this->data['Server']['slots'] = '';
				$this->data['Server']['payedTill'] = '';

				// Теперь сохранить изменения параметров
				$this->Server->id = $this->data['Server']['id'];
				$server = $this->Server->read();
				$this->RadioShoutcastParam->id = $server['RadioShoutcastParam'][0]['id'];
				$shoutcastParams['RadioShoutcastParam'] = $this->data['RadioShoutcastParam'][0];
				if ($this->RadioShoutcastParam->save($shoutcastParams)){
					/*
					 * Прежде, чем генерить конфиг, надо вытащить пароли из старого.
					 *
					 */
					$passwords = $this->changeShoutcastPass($id,'view');
					// Теперь надо сгенерировать новый конфиг и передать его скрипту.
					$newParams =  $this->RadioShoutcastParam->read();

					$newConfig =
'
; Template made by TeamServer.ru

; MaxUser.  The maximum number of simultaneous listeners allowed.
MaxUser='.$server['Server']['slots'].'

; Password is required to broadcast through the server, and to perform
; administration via the web interface to this server. THIS VALUE
; CANNOT BE BLANK.
Password='.$passwords[0].'

; IP port number your server will run on.  The
; value, and the value + 1 must be available.
PortBase='.$server['Server']['port'].'

; LogFile: file to use for logging. Can be \'/dev/null\' or \'none\'
; or empty to turn off logging.
LogFile=/home/client'.$server['User'][0]['id'].'/public_html/output/shoutcast_'.$server['Server']['id'].'.log

; RealTime displays a status line that is updated every second
; with the latest information on the current stream
RealTime='.$newParams['RadioShoutcastParam']['RealTime'].'

; ScreenLog controls whether logging is printed to the screen or not
; on *nix and win32 console systems.
ScreenLog=0

; ShowLastSongs specifies how many songs to list in the /played.html
; page.  The default is 10.  Acceptable entries are 1 to 20.
ShowLastSongs='.$newParams['RadioShoutcastParam']['ShowLastSongs'].'

; TchLog decides whether or not the DNAS logfile should track yp
; directory touches.  Adds and removes still appear regardless of
; this setting.
TchLog=Yes

; WebLog decides whether or not hits to http:// on this DNAS will
; be logged.
WebLog=No

; W3CEnable turns on W3C Logging.  W3C logs contain httpd-like accounts
; of every track played for every listener, including byte counts those listeners
; took.  This data can be parsed with tools like Analog and WebTrends, or given
; to third parties like Arbitron and Measurecast for their reporting systems.
W3CEnable='.$newParams['RadioShoutcastParam']['W3CEnable'].'

; W3CLog describes the name of the logfile for W3C logging.
W3CLog=/home/client'.$server['User'][0]['id'].'/public_html/output/shoutcast_w3c_'.$server['Server']['id'].'.log

; SrcIP, the interface to listen for source connections on (or to make relay
; connections on if relaying).
SrcIP='.$server['Server']['address'].'

; DestIP, IP to listen for clients on (and to contact yp.shoutcast.com)
DestIP='.$server['Server']['address'].'

; Yport, port to connect to yp.shoutcast.com on.
Yport=80

; NameLookups.  Specify 1 to perform reverse DNS on connections.
NameLookups='.$newParams['RadioShoutcastParam']['NameLookups'].'

; RelayPort and RelayServer specify that you want to be a relay server.
; Relay servers act as clients to another server, and rebroadcast.
; Set RelayPort to 0, RelayServer to empty, or just leave these commented
; out to disable relay mode.
RelayPort='.$newParams['RadioShoutcastParam']['RelayPort'].'
RelayServer='.$newParams['RadioShoutcastParam']['RelayServer'].'

; AdminPassword.  This password (if specified) changes the
; behavior of Password to be a broadcast-only password, and
; limits HTTP administration tasks to the password specified
; here.  The broadcaster, with the password above, can still
; log in and view connected users, but only the AdminPassword
; will grant the right to kick, ban, and specify reserve hosts.
; The default is undefined (Password allows control for both
; source and admin)
AdminPassword='.$passwords[1].'

; AutoDumpUsers controls whether listeners are disconnected if the source
; stream disconnects.
AutoDumpUsers='.$newParams['RadioShoutcastParam']['AutoDumpUsers'].'

; AutoDumpSourceTime specifies how long, in seconds, the source stream is
; allowed to be idle before the server disconnects it. 0 will let the source
; stream idle indefinately before disconnecting.
AutoDumpSourceTime='.$newParams['RadioShoutcastParam']['AutoDumpSourceTime'].'

; ContentDir specifies the directory location on disk of where to stream
; on-demand content from.  Subdirectories are supported as of DNAS 1.8.2.
ContentDir=./'.$newParams['RadioShoutcastParam']['ContentDir'].'/

; IntroFile can specify a mp3 file that will be streamed to listeners right
; when they connect before they hear the live stream.
IntroFile='.$newParams['RadioShoutcastParam']['IntroFile'].'

; BackupFile can specify a mp3 file that will be streamed to listeners over
; and over again when the source stream disconnects. AutoDumpUsers must be
; 0 to use this feature. When the source stream reconnects, the listeners
; are rejoined into the live broadcast.
BackupFile='.$newParams['RadioShoutcastParam']['BackupFile'].'

; TitleFormat specifies a format string for what title is sent to the listener.
TitleFormat='.$newParams['RadioShoutcastParam']['TitleFormat'].'

; URLFormat specifies a format string for what url is sent to the listener.
; URLFormat=

; PublicServer can be always, never, or default
PublicServer='.$newParams['RadioShoutcastParam']['PublicServer'].'

; AllowRelay determines whether or not other SHOUTcast servers will be
; permitted to relay this server.
AllowRelay='.$newParams['RadioShoutcastParam']['AllowRelay'].'

; AllowPublicRelay, when set to No, will tell any relaying servers not
; to list the server in the SHOUTcast directory (non-public), provided
; the relaying server\'s Public flag is set to default.
AllowPublicRelay='.$newParams['RadioShoutcastParam']['AllowPublicRelay'].'

; MetaInterval specifies how often, in bytes, metadata sent.
MetaInterval='.$newParams['RadioShoutcastParam']['MetaInterval'].'

; ListenerTimer is a value in minutes of maximum permitted time for
; a connected listener.  If someone is connected for longer than this
; amount of time, in minutes, they are disconnected.
ListenerTimer='.$newParams['RadioShoutcastParam']['MetaInterval'].'

; BanFile is the text file sc_serv reads and writes to/from
; for the list of clients prohibited to connect to this
; server.  It\'s automatically generated via the web
; interface.
BanFile='.$newParams['RadioShoutcastParam']['BanFile'].'

; RipFile is the text file sc_serv reads and writes to/from
; for the list of client IPs which are *ALWAYS* permitted
; to connect to this server (useful for relay servers).
; This file is automatically generated via the web
; interface.
RipFile='.$newParams['RadioShoutcastParam']['RipFile'].'

; RIPOnly, when set to Yes, will only allow IP addresses listed in the Reserved
; IP list to connect and relay.  All other connections for listening will be denied.
RIPOnly='.$newParams['RadioShoutcastParam']['RIPOnly'].'

; CpuCount is used to explicitly limit the DNAS to dominating a finite
; amount of processors in multiprocessor systems.
CpuCount=2

; Sleep defines the granularity of the client threads for sending data.
; DNAS 1.7.0, per client thread, will send up to 1,024 bytes of data
; per socket (or less depending on the window available), and then
; sleep for the provided duration before repeating the whole process.
; Note that making this value smaller will vastly increase CPU usage on
; your machine.  Increasing reduces CPU, but increasing this value too far
; will cause skips.  The value which seems most optimal for 128kbps
; streaming is 833 (833 microseconds per client poll) on our test labs.
; We wouldn\'t recommend setting it any lower than 100, or any higher than
; 1,024.
Sleep='.$newParams['RadioShoutcastParam']['Sleep'].'

; CleanXML strips some whitespace and linefeeds from XML output which
; confuses some (poorly written) XML parsers.  If you get XML rendering errors,
; try turning this on.
CleanXML='.$newParams['RadioShoutcastParam']['CleanXML'].'

';
					/*
					 * Тут генерить конфиг
					 */

					 /*
					  * Формируем запрос
					  */
					$serverIp   = $server['Server']['address'];
			 		$serverId   = $server['Server']['id'];
			 		$serverName = $server['GameTemplate'][0]['name'];
			 		$userId     = $server['User'][0]['id'];

			 		$iniPath = "/home/client".$userId."/servers/".$serverName."_".$serverId."/sc_serv.conf";

			 		$data = "action=write".
							"&cfgPath=".$iniPath.
							"&cfgText=".urlencode($newConfig);

			 		$request = "~configurator/scripts/write_user_configs.py?".$data;

			 		$response = $this->TeamServer->webGet($serverIp, 0, $request, "GET");


				}
				else
				{
					$this->set('errors', $this->RadioShoutcastParam->invalidFields());
					$this->Session->setFlash('Возникла ошибка при сохранении параметров.'.mysql_error(), 'flash_error');
				}
			}
		}
	}

	/* Основное предназначение функции - редактор параметров запуска */
	function editStartParams ($id = null){
		$this->DarkAuth->requiresAuth();

		if (@$id == null){
		 	$id = $this->data['Server']['id'];
		}

		if (intval($id) > 0){ // $id должен быть цифрой и больше нуля
			$this->loadModel('GameTemplate');
			$this->Server->id = $id;
			$server = $this->Server->read();
			// В зависимости от типа и шаблона, переадресуем на соответвующий контроллер
			// Да, можно это делать из функции, что привязана к типу, но
			// тогда будет сгененрирован дополнительный редирект 304,
			// что не есть хорошо.
			switch ( strtolower($server['Type'][0]['name']) ) {
				case 'hlds':
				case 'srcds':
				case 'cod':
				case 'ueds':
					// Эти типы во многом схожи, в частности в редактировании параметров
					$redirTo = 'editStartParamsSrcds';

					break;

				case 'radio':
				case 'voice':
					$redirTo = 'editStartParams'.
								ucfirst(strtolower($server['Type'][0]['name'])).
								ucfirst(strtolower($server['GameTemplate'][0]['name']));
					// В результате будет сгенерирован редирект вроде
					// editParamsVoiceMumble

					break;

				default:
					$redirTo = 'editStartParams'.ucfirst(strtolower($server['Type'][0]['name'])); // Сделать первый символ в верхнем регистре
					break;

			}

			$this->redirect(array('action'=>$redirTo,$id));
		}
		else
		{
			$this->Session->setFlash('Некорректный Server ID.', 'flash_error');
			$this->redirect(array('action'=>'result'));
		}

	}

	function editStartParamsSrcds ($id = null) {
		$this->DarkAuth->requiresAuth();





		if ($this->checkRights($id)){

			// Переменная для групировки ошибок
			$error = '';

			// $this->data получаем из функции setMap

			// Генерация нового одноразового хэша клиента
			$token=md5(rand(23658,8000064000).time());
			$this->Server->User->id = $this->DarkAuth->getUserId();
			$this->Server->User->saveField('tokenhash',$token);

			$this->loadModel('ServerModPlugin');
			$this->set('currentMap', $this->setMap($id));
			$this->Server->GameTemplate->id = $this->data['GameTemplate'][0]['id'];
			$this->Server->GameTemplate->unbindModel(array(
											'hasAndBelongsToMany' => array(
																'Mod',
																'Plugin',
																'Config',
																'Protocol',
																'Service',
																'Server'
													)));

			Cache::set(array('duration' => '+5 minutes'));

			if (($template = Cache::read('GameTemplateWithType_'.$this->data['GameTemplate'][0]['id'])) === false) {

				$template = $this->Server->GameTemplate->read();

				Cache::set(array('duration' => '+5 minutes'));
				Cache::write('GameTemplateWithType_'.$this->data['GameTemplate'][0]['id'], $template);
			}

			$this->ServerModPlugin->bindModel(array(
											'hasAndBelongsToMany' => array(
																'Service' => array()
													)));
			$server = $this->ServerModPlugin->find('first',
											array(
													'recursive' => '2',
													'conditions' => array(
																		'id' => $id
																		 )
												  ));

			if(!empty($server['Service'])){
				// Составить список подключенных услуг
				foreach ( $server['Service'] as $service ) {
       				$serviceIds[] = $service['id'];
				}
			}

			/* Если подключена услуга "Смена игры сервера" id = 3 */
			if (!empty($serviceIds) and in_array('3', $serviceIds)){
				/* Т.к. нельзя подключить сразу в модель HasOne вместе с HasAndBelongsToMany,
				 * запросим дату использования услуги отдельно */
				 // Надо посмотреть, не использовалась ли услуга за последние сутки
				$this->loadModel('UsedService');
				$this->UsedService->unbindModel(array(
										'belongsTo' => array(
													'Service',
													'Server'
														)));
				$usedServiceDate = $this->UsedService->find('first', array('conditions' => array(
																			   'server_id' => $id,
																			   'service_id' => '3'
																				)));
				// Время и дата, когда можно повторно использовать услугу
				if (!empty($usedServiceDate)){
					$minTimeToUseService = strtotime($usedServiceDate['UsedService']['date_used'].'+ 1 day');
				}
				else
				{
					$minTimeToUseService = 0;
				}
				if (time() > $minTimeToUseService){
					/* Т.к. мы ограничиваем выбор серверов для смены игры
					 * только тем же приватным типом,что и текущий сервер,
					 * нужно исключить из списка серверы, которые
					 * не поддерживают текущий тип.
					 * */
					if ($server['ServerModPlugin']['privateType'] == 1){ // приватный с паролем
						$priceExclude = 'pricePrivatePassword > 0';
					}
					else
					if ($server['ServerModPlugin']['privateType'] == 2){ // приватный с автоотключением
						$priceExclude = 'pricePrivatePower > 0';
					}
					else
					if ($server['ServerModPlugin']['privateType'] == 0) // Публичный
					{
						$priceExclude = 'pricePrivatePower >= 0';
					}

					// Сначала сделать выборку тех шаблонов, которые поддерживают смену игры

					$this->loadmodel('Service');
					$this->Service->bindModel(array(
													'hasAndBelongsToMany' => array(
																		'GameTemplate' => array(
																							'fields' => 'id, name',
																							'conditions' => array(
																													'id NOT' => $this->data['GameTemplate'][0]['id']
																													),
																							'order' => 'longname ASC'
																								)
													)));
					$this->Service->unbindModel(array(
													'hasAndBelongsToMany' => array('Server' )));

					$service3  = $this->Service->find('all',
														array(
															  'fields' => 'id, name',
															  'conditions' => array(
															  						'id' => '3'
															  						)));
					foreach ( $service3[0]['GameTemplate'] as $gameTemplate ) {
									$gameTemplateIds[] = $gameTemplate['id'];
								}


					/* Создать список выбора новой игры сервера */
					$this->Server->Type->bindModel(array(
													'hasAndBelongsToMany' => array(
																		'GameTemplate' => array(
																							'fields' => 'id, longname, price, pricePrivatePassword, pricePrivatePower',
																							'conditions' => array(
																													'id' => $gameTemplateIds,
																													'id NOT' => $this->data['GameTemplate'][0]['id'],
																													$priceExclude
																													),
																							'order' => 'longname ASC'
																								)
													)));
					$types  = $this->Server->Type->find('all',
														array(
															  'fields' => 'id, name',
															  'conditions' => array(
															  						'id NOT' => array('2','3','4')
															  						)));
					foreach ( $types as $type ) {

						foreach ( $type['GameTemplate'] as $gameTemplate ) {
							$gameTemplateList[$gameTemplate['id']] = $gameTemplate['longname'];
						}

					}

					asort($gameTemplateList);
					$this->set('gameTemplateList', $gameTemplateList);
				}
				else
				{
					$this->set('minTimeToUseService', $minTimeToUseService);
				}
			}
			$this->set('server',$server);

			/* Прочесть пароль из конфига - Начало*/
			if (!empty($template)){

				$getRconPass = false;
				/* Выбрать конфиг, в который писать по типу сервера*/
		 		if ($template['Type'][0]['name'] == 'srcds'
						or
					$template['Type'][0]['name'] == 'hlds'
					){

					$config = 'server.cfg';
					$configPath = $template['GameTemplate']['configPath'];
					$rootPath = 'servers/'.$template['GameTemplate']['name'].'_'.$id;
					$passParamName = 'sv_password';

					if (empty($server['ServerModPlugin']['rconPassword'])){
						$getRconPass = true;
					}
					else
					{
						$this->set('rconPassword', @$server['ServerModPlugin']['rconPassword']);
					}

					$delim = 'space';

					 }
		 		else
				if ($template['Type'][0]['name'] == 'cod'){
					if (empty($server['ServerModPlugin']['mod'])
							or
						$server['ServerModPlugin']['mod'] == 'ModWarfare')
						{

							$config = 'server.cfg';
							$configPath = 'main';

						}
						else
						{
							$config = 'modserver.cfg';
							$configPath = 'mods/'.$server['ServerModPlugin']['mod'];
						}
					$rootPath = 'servers/'.$template['GameTemplate']['name'].'_'.$id;
					$passParamName = 'set g_password';
					$delim = 'space';
				}
				else
				if ($template['Type'][0]['name'] == 'ueds')
				{
					if ($template['GameTemplate']['name'] == 'killingfloor'){
						$rootPath   = '.killingfloor';
						$configPath = 'System';
						$config     = 'KillingFloor-'.$id.'.ini';
						$passParamName = 'GamePassword';
					}

					$delim = 'eq';
				}
		 		/* Конец выбора конфига */

				if ($template['Type'][0]['name'] == 'srcds'
						or
					$template['Type'][0]['name'] == 'hlds'
						or
					$template['Type'][0]['name'] == 'cod'
						or
					$template['Type'][0]['name'] == 'ueds'
					){

						$data = 'id='.$id .
								'&p='.$passParamName .
								'&val=None' .
								'&desc=None' .
								'&conf='.$config .
								'&path='.$rootPath.'/'.$configPath .
								'&a=read' .
								'&d='.$delim;
						$requestStr = '/~configurator/scripts/subscript_read_write_param.py';

						$HttpSocket = new HttpSocket();
						$response = $HttpSocket->get($server['ServerModPlugin']['address'].$requestStr, $data);
			 			//pr($response);
				 		$xml = new Xml($response);
				 		$xmlAsArray = $xml->toArray();

				 		// Прасинг лога и ошибок
				 		$responseMessages = $this->parseXmlResponse($xmlAsArray);
				 		$error = $responseMessages['error'];

						if (!empty($xmlAsArray['Response']['paramValue'])){
							$this->set('serverPassword', $xmlAsArray['Response']['paramValue']);
						}
						else
						{
							$this->set('serverPassword', false);
						}

						if ($getRconPass === true){
							$data = 'id='.$id .
								'&p=rcon_password'.
								'&val=None' .
								'&desc=None' .
								'&conf='.$config .
								'&path='.$rootPath.'/'.$configPath .
								'&a=read' .
								'&d='.$delim;
							$requestStr = '/~configurator/scripts/subscript_read_write_param.py';

							$HttpSocket = new HttpSocket();
							$response = $HttpSocket->get($server['ServerModPlugin']['address'].$requestStr, $data);
				 			//pr($response);
					 		$xml = new Xml($response);
					 		$xmlAsArray = $xml->toArray();

					 		// Прасинг лога и ошибок
					 		$responseMessages = $this->parseXmlResponse($xmlAsArray);
					 		$error = $responseMessages['error'];

							if (!empty($xmlAsArray['Response']['paramValue'])){
								$this->set('rconPassword', $xmlAsArray['Response']['paramValue']);
							}
							else
							{
								$this->set('rconPassword', false);
							}
						}

					}
			}
			/* Прочесть пароль из конфига - Конец */

			/* Т.к. различия в редакторе конфигурации лишь косметические,
			 * не буду плодить функций, а ниже определю, как вид рендерить.
			 * Не знаю, насколько это неизящно, зато практично. Хоть и костыль.
			 */

			 if ( $template['GameTemplate']['name'] == 'csgo' or  $template['GameTemplate']['name'] == 'csgo-t128')
			 {

			 	/* Кэшировать на час */
				$data = $this->data;
				unset($this->data);

				Cache::set(array('duration' => '+1 hours'));
				if (($gameModesMain = Cache::read('gameModesTxt_'.$id)) === false
						or
					 ($mapGroupsMain = Cache::read('mapGroupsMain_'.$id)) === false	) {

					$gameModesMain = array();
					$mapGroupsMain = array('0' => 'Не устанавливать');

					$gameModesMainParsed = $this->editConfigCommon($id, 327, 'read');

					if ($gameModesMainParsed !== false)
					{
						$gameModesMainParsed = $this->KvParser->GetArray($gameModesMainParsed);

						// Вычленим основные игровые типы и режимы
						foreach ($gameModesMainParsed['params']['gameTypes'] as $key => $gameType)
						{
							$gameModesMain[$key]['id'] = $gameType['value'];

							foreach ($gameType['gameModes']  as $key2 => $gameMode)
							{
								$gameModesMain[$key]['gameModes'][$key2] = $gameMode['value'];
							}
						}

						// Основные группы карт
						foreach ($gameModesMainParsed['params']['mapgroups'] as $key => $mapgroup)
						{
							$mapGroupsMain[$key] = $key;
						}

					}

					Cache::set(array('duration' => '+1 hours'));
					Cache::write('gameModesTxt_'.$id, $gameModesMain);
					Cache::write('mapGroupsMain_'.$id, $mapGroupsMain);
					unset($gameModesMainParsed);
				}

				/* Пользовательский GameModes */
				unset($this->data);
				$gameModesUserParsed = $this->editConfigCommon($id, 326, 'read');

				if ($gameModesUserParsed !== false)
				{
					$gameModesUserParsed = $this->KvParser->GetArray($gameModesUserParsed);

					foreach ($gameModesUserParsed['params']['gameTypes'] as $key => $gameType)
					{
						if ( empty($gameModesMain[$key]['id']) and !empty($gameType['value']))
						{
							$gameModesMain[$key]['id'] = $gameType['value'];
						}

						foreach ($gameType['gameModes']  as $key2 => $gameMode)
						{
							if (empty($gameModesMain[$key]['gameModes'][$key2]))
							{
								$gameModesMain[$key]['gameModes'][$key2] = $gameMode['value'];
							}

						}

					}

					// Основные группы карт
					foreach ($gameModesUserParsed['params']['mapgroups'] as $key => $mapgroup)
					{
						if (!array_search($key, $mapGroupsMain))
						{
							$mapGroupsMain[$key] = $key;
						}

					}

					asort($mapGroupsMain);
				}

				// Список базовых режимов игры, как они называются официально
				$baseModesList = array( '0/0' => 'Classic Casual',
										'0/1' => 'Classic Competitive',
										'1/0' => 'Arms Race',
										'1/1' => 'Demolition'
										);

				// Теперь организовать меню выбора режима игры
				$gameModesList = array();
				foreach ($gameModesMain as $gameType => $gameMode) {
					foreach ($gameMode['gameModes'] as $gameModeName => $gameModeId) {
						$gameModeId = $gameMode['id'].'/'.$gameModeId;

						if (empty($baseModesList[$gameModeId]))
						{
							$gameModesList[$gameModeId] = ucfirst($gameType).': '.ucfirst($gameModeName);
						}
						else
						{
							$gameModesList[$gameModeId] = $baseModesList[$gameModeId];
						}

					}
				}

				$this->set('mapGroups', $mapGroupsMain);
				$this->set('gameModesList', $gameModesList);
				$this->data = $data;

				// Установить пункты меню по-умолчанию
				if (empty($this->data['Server']['mapGroup']))
				{
					$this->data['Server']['mapGroup'] = 0;
				}

				if (empty($this->data['Server']['mod']))
				{
					$this->data['Server']['mod'] = '0/0';
				}
			 }

			 if (@$template['Type'][0]['name'] == 'cod'){
			 	// Пулучить список установленных модов
			 	$data = "id=".$id;
		 		$requestStr = "/~configurator/scripts/subscript_cod_mod_list.py";

		 		$HttpSocket = new HttpSocket();
		 		$response = $HttpSocket->get($server['ServerModPlugin']['address'].$requestStr, $data);

		 		$xml = new Xml($response);
		 		$xmlAsArray = $xml->toArray();

		 		// Прасинг лога и ошибок
				$responseMessages = $this->parseXmlResponse($xmlAsArray);
				$error = $responseMessages['error'];


		 		// Парсинг списка модов
		 		// Для CoD2 задать директорию main в качестве мода
		 		if (@$template['GameTemplate']['name'] == 'cod2'){
		 			$modsList = array('main' => 'Базовый');
		 		}
		 		else
		 		{
		 			$modsList = array('' => 'Без мода');
		 		}

		 		if (!empty($xmlAsArray['Response']['List']['Dir'])){
		 			foreach ( $xmlAsArray['Response']['List']['Dir'] as $mod ) {
       					$modsList[$mod] = $mod;
       					if (@$template['GameTemplate']['name'] == 'cod4' and preg_match('/[A-Z]./', $mod) > 0 and $mod !='ModWarfare'){
       						$error .= 'Имя директории мода - '.$mod.' - содержит заглавные буквы. Имя директории мода может содержать только малые буквы и цифры.<br/>';
       					}
       					if (preg_match('/\W/', $mod) > 0){
       						$error .= 'Имя директории мода - '.$mod.' - содержит недопустимый символ. Имя директории мода может содержать только малые буквы и цифры.<br/>';
       					}

					}
		 		}
		 		else if (!empty($xmlAsArray['Response']['List']['dir']))
		 		{
		 			$modsList[$xmlAsArray['Response']['List']['dir']] = $xmlAsArray['Response']['List']['dir'];
		 		}
		 		else
		 		{
		 			if (!empty($server['ServerModPlugin']['mod'])){
		 				$error .= 'Не обнаружен мод сервера, хотя в строке запуска он установлен! Переинициализируйте сервер, либо загрузите нужный мод и установите его модом по умолчанию!<br/>';
		 			}
		 			else
		 			{
		 				// Для CoD2 не выводить эту ошибку
		 				if (@$template['GameTemplate']['name'] != 'cod2'){
		 					$error .= 'Не обнаружен мод сервера!<br/>';
		 				}
		 			}

		 		}

		 		// Вывод ошибки
		 		if (@$error != ''){
		 			$this->Session->setFlash('Возникла ошибка при получении списка установленных модов: <br/>'.$error, 'flash_error');
		 		}

		 		// Убрать ManuAdmin из списка запускаемых модов
		 		if (!empty($modsList['manuadmin'])){
		 			unset($modsList['manuadmin']);
		 		}

		 		$this->set('mods', $modsList);
			 	$this->render('edit_start_params_cod');
			 }
			 else
			 if (@$template['Type'][0]['name'] == 'ueds')
			 {
			 	$this->render('edit_start_params_ueds');
			 }

		}

	}

	function editStartParamsEac ($id = null) {
		$this->DarkAuth->requiresAuth();

		if ($this->checkRights($id)){

			$this->loadModel('ServerEac');

			$this->ServerEac->bindModel(array(
											'hasAndBelongsToMany' => array(
																'User' => array()
													)));

			$this->ServerEac->id = $id;

			$eacServer = $this->ServerEac->read();

			$this->set('eacServer', $eacServer);

			$this->set('id', $id);

			$this->Server->User->id = $eacServer['User'][0]['id'];
			$user = $this->Server->User->read();

			$serversIdsList = array();
			foreach ($user['Server'] as $serverId):
				$serversIdsList[] = $serverId['id'];
			endforeach;

			// Переходим к серверам
			// Нефиг запрашивать лишнюю информацию из базы
			$this->Server->unbindModel(array(
											'hasAndBelongsToMany' => array(
																'Plugin',
																'Location',
																'RootServer',
																'Service',
																'Order',
																'User',
																'VoiceMumbleParam',
																'RadioShoutcastParam'
													)));

			$userServers = $this->Server->find('all',array(
											'conditions' => array('id'=>$serversIdsList)));

			$serversForEac = array(0 => 'Выберите свой сервер...');
			$serversTypesForEac = ' if (serverId == 0)
											{
												eacType = "hl2";
											}
									else
									';
			$i = 0;
			$ownServerConnectedId = false;
			foreach ($userServers as $userServer) {
				if (@in_array($userServer['GameTemplate'][0]['id'], array(15,21,22,29,39,40))
						and
					!empty($userServer['Server']['address']))
				{
					$serversForEac[$userServer['Server']['id']] = '#'.$userServer['Server']['id'].
																  ' '.mb_strtoupper($userServer['GameTemplate'][0]['name']).
																  ': '.$userServer['Server']['address'].':'.$userServer['Server']['port'];

					// Если подключен EAC, то сравнить его адрес с этими из списка
					if (!empty($eacServer['Eac']))
					{
						if ($eacServer['Eac']['Address'] == $userServer['Server']['address'].':'.$userServer['Server']['port'])
						{
							$ownServerConnectedId = $userServer['Server']['id'];
						}
					}

					if (in_array($userServer['GameTemplate'][0]['id'], array(15, 21, 29, 39, 40)))
					{
						$eacType = 'hl2';
					}
					else
					{
						$eacType = 'hl1';
					}

					if ($i > 0)
					{
						$serversTypesForEac .= "\nelse\n";
					}

					$serversTypesForEac .= 'if (serverId == '.$userServer['Server']['id'].')
											{
												eacType = "'.$eacType.'";
											}';

					$i++;
				}
			}

			$this->set('serversForEac', $serversForEac);
			$this->set('serversTypesForEac', $serversTypesForEac);

			// Если сервер подключен, подготовить
			// данные для отображения
			if (!empty($eacServer['Eac']) and empty($this->data))
			{
				// Подключен собственный сервер
				if ($ownServerConnectedId !== false)
				{
					$this->data['Server']['ownServer'] = $ownServerConnectedId;
				}
				else
				{
					$this->data['Server']['connectedAddress'] = $eacServer['Eac']['Address'];
					$this->data['Server']['rconPass'] = $eacServer['Eac']['RCON'];
					$this->data['Server']['gameType'] = $eacServer['Eac']['Game'];
				}

				switch ($eacServer['Eac']['Flags']) {
					case '1':
						$this->data['Server']['eacPublic'] = 0;
						$this->data['Server']['eac32bit'] = 1;
						break;

					case '2':
						$this->data['Server']['eacPublic'] = 1;
						$this->data['Server']['eac32bit'] = 0;
						break;

					case '3':
						$this->data['Server']['eacPublic'] = 1;
						$this->data['Server']['eac32bit'] = 1;
						break;

					default:
						break;
				}
			}
			else
			if (!empty($this->data['Server']))
			{
				$this->loadModel('ServerTemplate');
				$this->loadModel('ServerClean');
				$this->loadModel('Eac');

				$req = $this->data['Server'];

				$checkOtherAddress = preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\:\d{5}$/', @$req['connectedAddress']);

				$this->ServerTemplate->id = $req['ownServer'];
				$serverForEac = $this->ServerTemplate->read();

				if (intval(@$req['ownServer']) > 0  and ($checkOtherAddress == 0 or empty($req['rconPass']) or $req['gameType'] > 0))
				{
					/* Выбран из списка свой сервер
					 Проверить наличия RCON-пароля в базе
					 Если его нет - создать и добавить в сообщение
					 Если есть, повторно прописать в конфиг и вывести это сообщение.

					 Добавить в таблицу EAC данные сервера

					 Сделать пробное подключение к RCON. Если неуспешно, сообщить,
					 что требуется ребут сервера.

					 Вывести итоговое сообщение
					*/

					if ($this->checkRights($req['ownServer'])){


						$this->ServerTemplate->id = $req['ownServer'];
						$serverForEac = $this->ServerTemplate->read();

						$message = '';

						if (empty($serverForEac['ServerTemplate']['rconPassword']))
						{
							$rconPass = $this->TeamServer->generatePass(9);
							$message .= 'У сервера нет пароля RCON, создаю новый.<br/>';
						}
						else
						{
							$rconPass = $serverForEac['ServerTemplate']['rconPassword'];
						}

						$thisData = $this->data['Server'];
						unset($this->data['Server']);

						$this->data['Server']['rconPassword'] = $rconPass;

						// Прописать пароль в конфиге

						if ($this->setRconPassword($req['ownServer'], 'return'))
						{
							$message .= 'Пароль RCON сохранен в конфиге и БД. Перегрузите сервер.<br/>';
						}
						else
						{
							$message .= 'Не удалось сохранить пароль RCON, попробуйте прописать его вручную - кнопка с шестеренкой.<br/>';
						}

						$this->data['Server'] = $thisData;

						// Необходимо отключить VAC у сервера
						if ($this->Server->saveField('vac', 0))
						{
							$message .= 'VAC игрового сервера отключен. Перегрузите сервер.<br/>';
						}
						else
						{
							$message .= 'Не удалось отключить VAC, попробуйте отключить его вручную - кнопка с шестеренкой.<br/>';
						}

						$eac['ServerClean']['address'] = $serverForEac['ServerTemplate']['address'];
						$eac['ServerClean']['port'] = $serverForEac['ServerTemplate']['port'];

						$eac['Eac']['Address'] = $serverForEac['ServerTemplate']['address'].':'.$serverForEac['ServerTemplate']['port'];
						$eac['Eac']['RCON'] = $rconPass;

						// Тип игры для EAC
						if (in_array($serverForEac['GameTemplate'][0]['id'], array(15, 21, 29)))
						{
							$eac['Eac']['Game'] = '2'; // HL2
						}
						else
						if (in_array($serverForEac['GameTemplate'][0]['id'], array(22)))
						{
							$eac['Eac']['Game'] = '1'; // HL1
						}

						// Флаги для EAC
						$eac['Eac']['Flags'] = 0;

						if ($this->data['Server']['eacPublic'] == 1)
						{
							$eac['Eac']['Flags'] += 2;
						}

						if ($this->data['Server']['eac32bit'] == 1)
						{
							$eac['Eac']['Flags'] += 1;
						}

						$eac['Eac']['active'] = 1;
						$eac['Eac']['user_id'] = $user['User']['id'];
						$eac['Eac']['server_id'] = $id;
						$eac['Eac']['payedTill'] = $eacServer['ServerEac']['payedTill'];

					}

					if (!empty($eacServer['Eac']))
					{
						$this->Eac->id = $eacServer['Eac']['id'];
					}


					if ($this->Eac->save($eac))
					{

						$this->ServerClean->id = $id;
						$this->ServerClean->save($eac);

						$message .= 'Данные сохранены в БД, сервер EAC активирован.<br/> Обновите главную страницу Панели управления и перегрузите игровой сервер.<br/>';

						// Пробное подключение к RCON

						$this->Session->setFlash($message, 'flash_success');
						$this->redirect(array('action' => 'editStartParamsEac', $id));
					}
					else
					{
						$message .= 'Не удалось сохранить данные в БД, попробуйте позднее.<br/>';
						$this->Session->setFlash($message, 'flash_error');
					}



				}
				else
				if ($checkOtherAddress > 0 and !empty($req['rconPass']) and $req['gameType'] > 0)
				{
					/* Указан сторонний сервер
					 Проверить, не поключен ли уже данный сервер к другому EAC
					 Если EAC только для внутренних серверов, проверить его IP по нашей базе
					 и вывести сообщение, если адрес не найден.

					 добавить в EAC данные сервера.

					 Сделать пробное подключение по RCON и вывести сообщение  - успешно или нет.


					*/
					$connectedEac = $this->Eac->find('all', array( 'conditions' => array('Address' => $req['connectedAddress'])));

					if (empty($connectedEac)
							or
						 (!empty($connectedEac)
							 and
						  $req['connectedAddress'] == $eacServer['ServerEac']['address'].':'.$eacServer['ServerEac']['port']
						 )
					   )
					{
						$eacAddress = preg_split('/\:/', $req['connectedAddress']);

						// Проверка на принадлженость адреса нашему хостингу
						if ($eacServer['GameTemplate'][0]['id'] == 37)
						{
							$this->loadModel('RootServerIp');

							if (!$this->RootServerIp->find('all', array( 'conditions' => array('ip' => $eacAddress[0]))))
							{
								$this->Session->setFlash('Введённый адрес не принадлежит нашему хостингу, а согласно выбранному тарифу вы можете подключить только сервер с нашего хостинга.', 'flash_error');
								$this->render();
								return false;
							}
						}

						$eac['ServerClean']['address'] = $eacAddress[0];
						$eac['ServerClean']['port']    = $eacAddress[1];

						$eac['Eac']['Address'] = $req['connectedAddress'];
						$eac['Eac']['RCON']    = $req['rconPass'];

						// Тип игры для EAC
						if (in_array($req['gameType'], array(1, 2)))
						{
							$eac['Eac']['Game'] = $req['gameType'];
						}
						else
						{
							$this->Session->setFlash('Вы указали некорректную игру (ввели её вручную? Ай-ай-ай!).', 'flash_error');
							$this->render();
							return false;
						}

						// Флаги для EAC
						$eac['Eac']['Flags'] = 0;

						if ($this->data['Server']['eacPublic'] == 1)
						{
							$eac['Eac']['Flags'] += 2;
						}

						if ($this->data['Server']['eac32bit'] == 1)
						{
							$eac['Eac']['Flags'] += 1;
						}

						$eac['Eac']['active'] = 1;
						$eac['Eac']['user_id'] = $user['User']['id'];
						$eac['Eac']['server_id'] = $id;
						$eac['Eac']['payedTill'] = $eacServer['ServerEac']['payedTill'];

						if (!empty($eacServer['Eac']))
						{
							$this->Eac->id = $eacServer['Eac']['id'];
						}

						$message = '';

						if ($this->Eac->save($eac))
						{

							$this->ServerClean->id = $id;
							$this->ServerClean->save($eac);

							$message .= 'Данные сохранены в БД, сервер EAC активирован.<br/> Обновите главную страницу Панели управления, отключите VAC у указанного игрового сервера и перегрузите его.<br/>';

							// Пробное подключение к RCON

							$this->Session->setFlash($message, 'flash_success');
							$this->redirect(array('action' => 'editStartParamsEac', $id));
						}
						else
						{
							$message .= 'Не удалось сохранить данные в БД, попробуйте позднее.<br/>';
							$this->Session->setFlash($message, 'flash_error');
						}

					}
					else
					{
						$this->Session->setFlash('Введённый сервер уже подключен к другому EAC. Либо отключите его там, либо напишите в техподдержку.', 'flash_error');
					}

					//pr($this->data['Server']);
				}
				else
				{
					// Ошибочные параметры
					$this->Session->setFlash('Данные введены некорректно.', 'flash_error');
				}


				//$this->redirect(array('action' => 'editStartParamsEac', $id));
				//$this->data['Server'] = $req;
			}
		}

	}

    /*
	 * Смена имени сервера.
	 * TODO: Сделать смену не только в базе, но и в конфигах.
	 */
	function changeName ($id = null){
		$this->DarkAuth->requiresAuth();
		$this->loadModel('ServerCore');

		if (is_null($id) && !empty($this->data['ServerCore']['id'])){
			$id = $this->data['ServerCore']['id'];
		}
		else
		if (is_null($id) && empty($this->data['ServerCore']['id']))
		{
			$this->Session->setFlash('Не указан ID сервера', 'flash_error');
			$this->redirect(array('action'=>'index'));
			return false;
		}

		if ($this->checkRights($id)){

			$this->ServerCore->id = $id;

			if ($this->data){

					//Чтобы не пихали всякое, ограничу сохранение только двумя параметрами
					$server['ServerCore']['name'] = strip_tags($this->data['ServerCore']['name']);
					$server['ServerCore']['desc'] = strip_tags($this->data['ServerCore']['desc']);

					if ($this->ServerCore->save($server)){
						// Теперь сохранить имя сервера в конфиг


						$server = $this->ServerCore->find(array('id' => $id), array('id', 'name', 'desc', 'address'));

						/* Выбрать имя параметра - Начало*/
						if (!empty($server['Type'])){

							/* Выбрать конфиг, в который писать по типу сервера*/
					 		if ($server['Type'][0]['name'] == 'srcds'
									or
								$server['Type'][0]['name'] == 'hlds'
								){

									$this->data['Server']['paramName'] = 'hostname';

								 }
					 		else
							if ($server['Type'][0]['name'] == 'cod')
							{
								if ($server['GameTemplate'][0]['name'] == 'cod2')
								{
									$this->data['Server']['paramName'] = 'set sv_hostname';
								}
								else
								{
									$this->data['Server']['paramName'] = 'sets sv_hostname';
								}

								$this->data['Server']['nameInGame'] = iconv('UTF8', 'CP1251', $this->data['Server']['nameInGame']);

							}
							else
							if ($server['Type'][0]['name'] == 'ueds')
							{

								$this->data['Server']['paramName'] = 'ServerName';

							}
					 		/* Конец выбора конфига */

							$this->data['Server']['paramValue'] = $this->data['ServerCore']['nameInGame'];

						if ($this->setConfigParam( $id, 'return')){
							$this->Session->setFlash('Новое имя сохранено в конфиге и базе. Перегрузите сервер или смените карту, чтобы новое имя стало отображаться.', 'flash_success');
						}

					}
					else
					{
						$this->Session->setFlash('Возникла ошибка.'.mysql_error(), 'flash_error');
					}

					$this->redirect(array('action'=>'index'));

				}
			}
			else
			{
				$this->data = $this->ServerCore->find(array('id' => $id), array('id', 'name', 'desc', 'address'));

				/* Прочесть имя сервера из конфига - Начало*/
				if (!empty($this->data['Type'])){

					/* Выбрать конфиг, в который писать по типу сервера*/
			 		if ($this->data['Type'][0]['name'] == 'srcds'
							or
						$this->data['Type'][0]['name'] == 'hlds'
						){

						$config = 'server.cfg';
						$configPath = $this->data['GameTemplate'][0]['configPath'];
						$rootPath = 'servers/'.$this->data['GameTemplate'][0]['name'].'_'.$id;
						$passParamName = 'hostname';
						$delim = 'space';

						 }
			 		else
					if ($this->data['Type'][0]['name'] == 'cod'){
						if (empty($this->data['Mod'])
								or
							$this->data['Mod'][0]['name'] == 'ModWarfare')
							{

								$config = 'server.cfg';
								$configPath = 'main';

							}
							else
							{
								$config = 'modserver.cfg';
								$configPath = 'mods/'.$this->data['Mod'][0]['name'];
							}
						$rootPath = 'servers/'.$this->data['GameTemplate'][0]['name'].'_'.$id;

						if ($this->data['GameTemplate'][0]['name'] == 'cod2'){
							$passParamName = 'set sv_hostname';
						}
						else
						{
							$passParamName = 'sets sv_hostname';
						}


						$delim = 'space';
					}
					else
					if ($this->data['Type'][0]['name'] == 'ueds')
					{
						if ($this->data['GameTemplate'][0]['name'] == 'killingfloor'){
							$rootPath   = '.killingfloor';
							$configPath = 'System';
							$config     = 'KillingFloor-'.$id.'.ini';
							$passParamName = 'ServerName';
						}

						$delim = 'eq';
					}
			 		/* Конец выбора конфига */

					if ($this->data['Type'][0]['name'] == 'srcds'
							or
						$this->data['Type'][0]['name'] == 'hlds'
							or
						$this->data['Type'][0]['name'] == 'cod'
							or
						$this->data['Type'][0]['name'] == 'ueds'
						){

							$data = 'id='.$id .
									'&p='.$passParamName .
									'&val=None' .
									'&desc=None' .
									'&conf='.$config .
									'&path='.$rootPath.'/'.$configPath .
									'&a=read' .
									'&d='.$delim;
							$requestStr = '/~configurator/scripts/subscript_read_write_param.py';

							$HttpSocket = new HttpSocket();
							$response = $HttpSocket->get($this->data['ServerCore']['address'].$requestStr, $data);
				 			//pr($response);
					 		$xml = new Xml($response);
					 		$xmlAsArray = $xml->toArray();

					 		// Прасинг лога и ошибок
					 		$responseMessages = $this->parseXmlResponse($xmlAsArray);
					 		$error = $responseMessages['error'];

							if (!empty($xmlAsArray['Response']['paramValue'])){
								$this->data['ServerCore']['nameInGame'] =  $xmlAsArray['Response']['paramValue'];
							}
							else
							{
								$this->data['ServerCore']['nameInGame'] = NULL;
							}

						}
				} /* Прочесть имя сервера из конфига - Конец */

			} /* Прочесть данные сервера */

		} /* Проверка прав на сервер */
	}

	/*
	 * Смена пароля SuperUser сервера Mumble
	 * 1) Запрос к скрипту на сервере, который генерирует
	 *    новый пароль, сохраняет его в базу Mumble,
	 * 	  и выдает его обратно сюда.
	 * 2) Запись статуса с паролем в flash
	 * 	  и редирект на result, чтобы вывести новый пароль пользователю.
	 */
	function changeMumbleRootPass( $id = null, $action = null){
		$this->DarkAuth->requiresAuth();

		if ($action == 'change'){
			if ($this->checkRights($id)){

				$this->Server->id = $id;
				$server = $this->Server->read();

				$serverIp = $server['Server']['address'];
				$serverPort = $server['Server']['port'];
		 		$serverId = $server['Server']['id'];
		 		$serverName = $server['GameTemplate'][0]['name'];
		 		$serverType = $server['Type'][0]['name'];
		 		$userId = $server['User'][0]['id'];

				$fp = @fsockopen($serverIp, 80, $errno, $errstr, 10);

				if (!$fp) {

				    $this->Session->setFlash("Невозможно подключиться к серверу: <br />\n"."$errstr ($errno)<br />\n", 'flash_error');

				} else

				{
					$data = "action=change".
							"&id=".$serverId;
					$out = "POST /~client".$userId."/common/.mumble_change_root_pass.py?".$data." HTTP/1.1\r\n";
				    $out .= "Host: ".$serverIp."\r\n";
					$out .= "Connection: Close\r\n\r\n";

				    fwrite($fp, $out);

				    $response = "";

				    while (!feof($fp)) {
				        $response.=fgets($fp, 1024);
				    }
				    fclose($fp);


					$response=split("\r\n\r\n",$response);
				    $header=$response[0];
				    $responsecontent=$response[1];
				    if(!(strpos($header,"Transfer-Encoding: chunked")===false)){
				        $aux=split("\r\n",$responsecontent);
				        for($i=0;$i<count($aux);$i++)
				            if($i==0 || ($i%2==0))
				                $aux[$i]="";
				        $responsecontent=implode("",$aux);
				    }


		 		 		$var = eregi("<!-- RESULT START -->(.*)<!-- RESULT END -->", $responsecontent, $out);
		 		 		$responsecontent = trim($out[1]);

			 		 	if ($responsecontent != "error"){
			 		 		$this->set('newPass',$responsecontent);
			 		 		$this->Session->setFlash("Запомните новый пароль: <strong>".$responsecontent."</strong><br/>\n Если вы потеряете пароль, его необходимо будет сгенерировать снова.", 'flash_success');
			 		 	}
						else
						{
							$this->Session->setFlash("Произошла ошибка. Попробуйте позднее.".$responsecontent, 'flash_error');
						}

				}

				$this->redirect(array('action'=>'result'));
			}

		}
	}

	/*
	 * Смена паролей трансляции и Админа для сервера SHOUTcast
	 * Т.к. пароли его хранятся в конфиге в открытом виде, а
	 * также конфиг имеет права отдельного системного пользователя,
	 * надо пускать скрипт от имени configurator.
	 * 1) Запрос к скрипту на сервере.
	 * 		view - считать из конфига пароли и выдать их сюда
	 * 		changePass      - изменить пароль транслятора
	 * 		changeAdminPass - изменить пароль Админа
	 * 2) Скрипт переписывает пароль в конфиг или же выводит уже существующий
	 * 3) Если был запрос на смену, то редирект на себя же с командой view,
	 * 	  чтобы считать повторно пароль из конфига. Почему так? Чтобы убедиться,
	 *    что новый пароль прописан в конфиг корректно.
	 */
	function changeShoutcastPass ( $id = null, $action = 'view'){
		$this->DarkAuth->requiresAuth();
		// Возможные действия, для безопасности
		$actions = array('view', 'changePass', 'changeAdminPass');
		if ($action){
			if ($this->checkRights($id)){

				$this->Server->id = $id;
				$this->set('id',$id);

				$server = $this->Server->read();

				$serverIp   = $server['Server']['address'];
				$serverPort = $server['Server']['port'];
		 		$serverId   = $server['Server']['id'];
		 		$serverName = $server['GameTemplate'][0]['name'];
		 		$serverType = $server['Type'][0]['name'];
		 		$userId     = $server['User'][0]['id'];

		 		// Подготовить запрос для webGet
		 		if ( in_array($action, $actions) ){
		 			$data = "action=".$action.
							"&cfgPath=/home/client".$userId."/servers/shoutcast_".$id."/sc_serv.conf";
		 		}

				$request = "~configurator/scripts/change_pass_shoutcast.py?".$data;

				$response = $this->TeamServer->webGet($serverIp, 0, $request);

				if ($response !== false){

					$var = eregi("<!-- RESULT START -->(.*)<!-- RESULT END -->", $response, $out);
		 			$response = trim($out[1]);

		 			if ($response != 'error'){
		 				if ($action == 'view'){
		 					$passwords=split(",", $response);
		 					$this->set('passwords', split(",", $response));
		 					return $passwords;
		 				}
		 				else
		 				{
		 					$this->Session->setFlash("Смена пароля прошла успешно.", 'flash_success');
		 					$this->redirect(array('action'=>'changeShoutcastPass', $id));
		 				}
		 			}
		 			else
		 			{
		 				$this->Session->setFlash("Произошла ошибка. Попробуйте позднее. ".$response, 'flash_error');
		 			}

				}




			}
		}
	}

	function editConfigCommon($serverId = null, $configId = null, $action = null, $editor = null){

		 $this->DarkAuth->requiresAuth();
		 $this->loadModel('Config');

		 // $game - временно, пока не исправлю редктор для COD
		 if (!is_null($editor))
		 {
		 	$this->Session->write('editorType', $editor);
		 }
		 else
		 if (is_null($editor) and $this->Session->check('editorType'))
		 {
		 	$editor = $this->Session->read('editorType');
		 }
		 else
		 if (is_null($editor) and !$this->Session->check('editorType'))
		 {
		 	$editor = 'extended';
		 }

		 $this->set('editorType', $editor);

		 // Вычленим реального пользователя
		 $sessionUser = $this->DarkAuth->getAllUserInfo();

		 $sessionUserId    = $sessionUser['User']['id'];
		 $sessionUserGroup = $sessionUser['Group'][0]['id'];

		 if (!empty($this->data)){

		 	$serverId = @$this->data['Server']['id'];
		 	$action   = @$this->data['Server']['action'];
		 	$configId   = @$this->data['Server']['configId'];
		 	$configText = @$this->data['Server']['configText'];

		 }

	 	 if (@$configId and @$action){
		 // Проверим  - владееет ли пользователь сессии этим сервером?
		 if ($this->checkRights($serverId)){

		 		$this->Server->id = $serverId;
		 		$this->data = $this->Server->read();
		 		$this->data['Server']['configId'] = $configId;

		 		$serverIp = $this->data['Server']['address'];
		 		$serverId = $this->data['Server']['id'];
		 		$serverName = $this->data['GameTemplate'][0]['name'];
		 		$serverType = $this->data['Type'][0]['name'];
		 		$rootPath = $this->data['GameTemplate'][0]['addonsPath'];
		 		$userId = $this->data['User'][0]['id'];

				/*
				 * Вычленим путь и имя конфига
				 * Нет желания отдавать запросы к базе скрипту,
				 * который запускается с правами пользователя,
				 * потому все рабочие запросы делаем тут.
				 */

				 if (intval($configId) > 0){
				 	$this->Config->id = $configId;
				 	$config = $this->Config->read();
				 	$configName = $config['Config']['name'];
				 	$configPath = $config['Config']['path'];
				 }
				 else if (intval($configId) == 0 && strlen($configId) > 1)
				 {
				 	if ($serverType == 'cod'){
				 		$configPath = 'mods/'.trim($configId,'.,/\\');;
				 		$configName = 'modserver.cfg';
				 	}
				 }


				 /*
				  * Нельзя выводить кнопку "Создать из шаблона" для
				  * модов, плагинов и т.д. ,
				  * т.к. шаблон конфигов априоре может быть только
				  * для сервера. Потому сравниваем найденный конфиг выше,
				  * и привязанные к серверу. Если совпадут - значит
				  * выводим кнопку.
				  */
				 $this->Server->GameTemplate->id = $this->data['GameTemplate'][0]['id'];
				 $template = $this->Server->GameTemplate->read();

				 foreach ( $template['Config'] as $serverConfig ) {
       				if ($serverConfig['id'] == $configId){
       					$this->data['Server']['configType'] = 'server';
       					break;
       				}
				 }


				 // Совершаем запрос и форматируем вывод

				/* Чтение конфига и создание конфига из шаблона производим
				 * через GET - так быстрее и проще.
				 * Запись конфига производим через POST - так надо, чтоб можно
				 * было передать большой объем текста
				 */

				if ($action == "read"){
					$data = "action=read".
							"&server=".$serverName.
							"&serverId=".$serverId.
							"&serverType=".$serverType.
							"&rootPath=".urlencode($rootPath).
							"&configPath=".$configPath.
							"&configName=".$configName.
							"&configText=false";
				}
				else if ($action == "create"){
					$data = "action=create".
							"&server=".$serverName.
							"&serverId=".$serverId.
							"&serverType=".$serverType.
							"&rootPath=".urlencode($rootPath).
							"&configPath=".$configPath.
							"&configName=".$configName.
							"&configText=false";
				}
				else if ($action == "write"){
					$data = "action=write".
							"&server=".$serverName.
							"&serverId=".$serverId.
							"&serverType=".$serverType.
							"&rootPath=".urlencode($rootPath).
							"&configPath=".$configPath.
							"&configName=".$configName.
							"&configText=".urlencode($configText);
				}

				$req = "/~client".$userId."/common/.edit_config.py";
				$HttpSocket = new HttpSocket();

				if ($action == "write"){
					$responsecontent = $HttpSocket->post("http://".$serverIp.$req, $data);
				}
				else
				{
					$responsecontent = $HttpSocket->get("http://".$serverIp.$req, $data);
				}

	 		 	if ($action == "read"){
		 		 	$var = eregi("<!-- CONFIG START -->(.*)<!-- CONFIG END -->", $responsecontent, $out);

		 		 	$responsecontent = $out[1];
		 		 	$responsecontent = chop(trim($responsecontent));
		 		 	if (strlen($responsecontent)>1){
		 		 		$this->set('result',$responsecontent);
		 		 		return $responsecontent;
		 		 	}
		 		 	else
		 		 	{
		 		 		$this->set('result',"// Конфиг отсутствует. Впишите сюда текст и\n" .
		 		 							"// нажмите сохранить, чтобы создать новый.\n" .
		 		 							"// Вы также можете создать конфиг из нашего\n" .
		 		 							"// типового шаблона, если таковой существует.\n" .
		 		 							"// Для этого нажмите \"Создать из шаблона\", \n" .
		 		 							"// если есть такая кнопка."
		 		 							);
		 		 		return false;
		 		 	}

	 		 	}
	 		 	else if ($action == "create"){
	 		 		if ($responsecontent)
	 		 		{
	 		 			$var = eregi("<!-- RESULT START -->(.*)<!-- RESULT END -->", $responsecontent, $out);
	 		 			$responsecontent = trim($out[1]);
	 		 		}
	 		 		else
	 		 		{
	 		 			$responsecontent = "Создание конфига не удалось. Нет доступа к серверу. Сообщите в техподдержку.";
	 		 		}


		 		 	if ($responsecontent == "success"){
		 		 		$this->Session->setFlash('Конфиг создан. Откройте редактор снова.', 'flash_success');
		 		 		$this->redirect(array('action'=>'result',$serverId));
		 		 	}
		 		 	else{
		 		 		$this->Session->setFlash('Произошла ошибка: '.$responsecontent, 'flash_error');
		 		 		$this->redirect(array('action'=>'result',$serverId));
		 		 	}
	 		 	}
	 		 	else if ($action == "write"){
	 		 		if ($responsecontent)
	 		 		{
	 		 			$var = eregi("<!-- RESULT START -->(.*)<!-- RESULT END -->", $responsecontent, $out);
	 		 			$responsecontent = trim($out[1]);
	 		 		}
	 		 		else
	 		 		{
	 		 			$responsecontent = "Сохранение не удалось. Нет доступа к серверу. Сообщите в техподдержку.";
	 		 		}


		 		 	if ($responsecontent == "success"){
		 		 		$this->Session->setFlash('Конфиг сохранён. Перезапустите сервер.', 'flash_success');
		 		 		$this->redirect(array('action'=>'result',$serverId));
		 		 	}
		 		 	else{
		 		 		$this->Session->setFlash('Произошла ошибка: '.$responsecontent, 'flash_error');
		 		 		$this->redirect(array('action'=>'result',$serverId));
		 		 	}
	 		 	}
		 	}
		}
		else
		{
			$this->Session->setFlash('Не введены все необходимые параметры', 'flash_error');
		}


	}

	function rcon($id = null, $isHltv = false) {
		$this->DarkAuth->requiresAuth();
		$this->Server->id = $id;
		$this->Server->unbindModel(array(
										'hasAndBelongsToMany' => array(
															'GameTemplate',
															'Mod',
															'Plugin',
															'Service',
															'Order',
															'User',
															'RootServer',
															'VoiceMumbleParam',
															'RadioShoutcastParam'
												)));
		$server = $this->Server->read();
		$this->Session->delete('rconPassword');
		$this->set('serverType', $server['Type'][0]['name']);
		$this->set('serverID',$id);
		$this->set('isHltv',$isHltv);
	}

	/*
	 * Работа с консолью в серверах RCON
	 * Пароль для доступа к серверу брать из конфига.
	 * Чтобы не дёргать его каждый раз из конфига,
	 * держать его переменной на всю сессию. Пусть
	 * и пишется в явном виде в сессию,
	 * но это принадлежит пользователю.
	 * Ради безопасности не хранить пароль в открытом виде в общей базе.
	 */
	function rconResult( $id = null, $command = null, $isHltv = false, $return = 'parsed' ) {
		//pr($this->params);;
		if (is_null($id) && !empty($this->params['url'])){
			$id = $this->params['url']['id'];
			$command = @$this->params['url']['command'];
			$isHltv = @$this->params['url']['isHltv'];
			settype($isHltv, 'bool');
		}
		$this->DarkAuth->requiresAuth();
		// Проверка прав на сервер
		if ($this->checkRights($id)){
			$this->Server->id = $id;
			$this->Server->unbindModel(array(
												'hasAndBelongsToMany' => array(
																	'Mod',
																	'Plugin',
																	'Service',
																	'Order',
																	'RootServer',
																	'VoiceMumbleParam',
																	'RadioShoutcastParam'
														)));

			$server = $this->Server->read();
			$serverTemplate = $server['GameTemplate'][0]['name'];
			$serverType = $server['Type'][0]['name'];
			$serverIp = $server['Server']['address'];
			$serverConfigPath = $server['GameTemplate'][0]['configPath'];
			$userId = $server['User'][0]['id'];
			if ($isHltv === false){
				// Если игра - COD, то установить, откуда и какой брать конфиг
				$msgNoPass = '<strong>RCON-пароль сервера не задан.<br/>Задайте его в Параметрах запуска или в файле server.cfg<br/>пропишите параметр rcon_password.</strong>';
				$msgNoFile = '<strong>RCON-пароль сервера не задан.<br/>Задайте его в Параметрах запуска или создайте server.cfg <br/>и пропишите в нём параметр rcon_password.</strong>';
				$rconPort = $server['Server']['port'];

				if ($serverType == 'cod'){
					if (strtolower($server['Server']['mod']) == 'modwarfare' or $server['Server']['mod'] == ''){
						$rconConfig = $serverConfigPath."/server.cfg";
					}
					else
					{
						$rconConfig = $server['GameTemplate'][0]['rootPath']."/mods/".$server['Server']['mod']."/modserver.cfg";
						$msgNoPass = '<strong>RCON-пароль сервера не задан.<br/>Задайте его в Параметрах запуска или в файле modserver.cfg<br/>пропишите параметр rcon_password.</strong>';
						$msgNoFile = '<strong>RCON-пароль сервера не задан.<br/>Задайте его в Параметрах запуска или создайте modserver.cfg <br/>и пропишите в нём параметр rcon_password.</strong>';
					}
				}
				else
				{
					$rconConfig = $serverConfigPath."/server.cfg";
				}

			}
			else
			{
				$rconConfig = 'hltv.cfg';
				$msgNoPass = '<strong>В файле hltv.cfg отсутствует <br/> параметр adminpassword.</strong>';
				$msgNoFile = '<strong>Отсутсвует файл конфигурации hltv.cfg, <br/> в котором необходимо прописать параметр rcon_password.</strong>';
				$rconPort = $server['Server']['port'] + 1015;
			}

			/*
			 * Получить пароль из конфига сервера,
			 * если его нет в сессии
			 */
			if (empty($server['Server']['rconPassword']) && !$this->Session->check('rconPassword.'.$id)){
				/*******************************/


				// Подготовить запрос для webGet
		 			$data = "action=get".
		 					"&pass=none".
							"&config=/home/client".$userId.
							"/servers/".$serverTemplate."_".$id."/".
							$rconConfig;

				$request = "~client".$userId."/common/.rcon_pass_change.py?".$data;
				$response = $this->TeamServer->webGet($serverIp, 0, $request);

				if ($response !== false){

					$var = eregi("<!-- PASS START -->(.*)<!-- PASS END -->", $response, $out);
		 			$response = trim($out[1]);

		 			if ($response != 'error'){

		 				if ($response == 'nopass'){
							$this->set('rconResult',$msgNoPass);

							// Возврат ответа в функцию без рендеринга
							if ($return == 'clean')
							{
								return false;
							}
		 				}
		 				else if ($response == 'nofile')
		 				{
			 				$this->set('rconResult',$msgNoFile);

			 				// Возврат ответа в функцию без рендеринга
							if ($return == 'clean')
							{
								return false;
							}
		 				}
		 				else //Иначе считаем ответ сервера паролем.
		 				{
		 					// И пишем его в сессию
		 					$this->Session->write('rconPassword.'.$id, $response);
		 					$rconPassword = $response;
		 				}
		 			}
		 			else
		 			{
		 				$this->Session->setFlash("Произошла ошибка. Попробуйте позднее. ".$response, 'flash_error');

		 				// Возврат ответа в функцию без рендеринга
						if ($return == 'clean')
						{
							return false;
						}
		 			}

				}
				else
				{
					$this->set('rconResult','<strong>Не удалось прочесть файл конфигурации.</strong>');

					// Возврат ответа в функцию без рендеринга
					if ($return == 'clean')
					{
						return false;
					}
				}


				/*******************************/

			}
			else
			if (!empty($server['Server']['rconPassword'])){
				$rconPassword = $server['Server']['rconPassword'];
			}
			else
			{
				// Считать пароль RCON для данного сервера из сессии
				$rconPassword = $this->Session->read('rconPassword.'.$id);
			}
			$this->set('serverID',$id);

			if (@$rconPassword){
				/* Для hlds и srcds протоколы отличаются
				 */
				if ($serverType == "hlds"){
					$rcon = new ValveRcon($rconPassword, $server['Server']['address'], $rconPort, ValveRcon::PROTO_CLASSIC);
				}
				else if ($serverType == "srcds")
				{
					$rcon = new ValveRcon($rconPassword, $server['Server']['address'], $rconPort, ValveRcon::PROTO_SOURCE);
				}
				else if ($serverType == "cod")
				{
					$rcon = new CommonRcon($server['Server']['address'], $rconPort, $rconPassword);
					$rcon->set_password($rconPassword);
				}

				try{
					$rcon->connect();
					$rcon->authenticate();

					if (!empty($command)){
						$rconAnswer = $rcon->execute($command);

						// Возврат ответа в функцию без рендеринга
						if ($return == 'clean')
						{
							return $rconAnswer;
						}

						$rconResult = "<strong>console:> ".$command."</strong>\n";
						/*
						 * Отформатируем вывод команд stats и status
						 * в виде красивой таблицы
						 */
						if (@$rconAnswer and ($command == "status" or $command == "stats")){
							$rconStrings = explode("\n", @$rconAnswer); // Разбивка текста на строки
							$iteration = 0;
							$infoIteration = 0;

							/* Patterns*/
							$pattern['info'] = '/^(?!#)(?P<name>[a-zA-Z\/\s]*):(?P<value>.*)$/';
							$pattern['valveStatusHeader'] = '/^\#\suserid.*$/';
							$pattern['codHeader'] = '/\b(num)\s+(score)\s+(ping)\s+(guid)\s+(name)\s+(lastmsg)\s+(address)\s+(qport)\s+(rate)\b/i';
							$pattern['valveBot'] = '/^\#\s*(?P<userid>\d+)\s\"(?P<name>.*)\"\s*BOT\s*(?P<state>\w+)$/';
							$pattern['valvePlayer'] = '/^\#\s*(?P<userid>\d+)\s\"(?P<name>.*)\"\s*(?P<uniqueid>STEAM_[01]:[01]:[0-9]{4,11})\s*(?P<connected>(?:\d{1,2}:){1,3}\d{1,3})\s*(?P<ping>\d{1,20})\s*(?P<loss>\d{1,16})\s*(?P<state>\w+)\s*(?P<adr>\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}:\d{1,5})$/i';
							$pattern['valveL4DPlayer'] = '/^\#\s*(?P<userid>\d+\s*\d+)\s*\"(?P<name>.*)\"\s*(?P<uniqueid>STEAM_[01]:[01]:[0-9]{4,11})\s*(?P<connected>(?:\d{1,2}:){1,3}\d{1,3})\s*(?P<ping>\d{1,20})\s*(?P<loss>\d{1,16})\s*(?P<state>\w+)\s*(?P<rate>\d+)\s*(?P<adr>\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}:\d{1,5})$/i';
							$pattern['codPlayer'] = '/^(?:\s+|\b)(?P<num>\d+)\s+(?P<score>\d+)\s+(?P<ping>\d+)\s+(?P<guid>[a-z0-9]+)\s+(?P<name>.+\^7\b)\s+(?P<lastmsg>\w+)\s+(?P<address>\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}:(?:\d|-\d){1,5})\s+(?P<qport>(?:\d|-\d){1,5})\s+(?P<rate>\d+)$/i';
							$pattern['valveStatsHeader'] = '/^(CPU)\s*(In\s\(KB\/s\))\s*(Out \(KB\/s\))\s*(Uptime)\s*(Map\schanges)\s*(FPS)\s*(Players)\s*(Connects)$/i';
							$pattern['valveHL1StatsHeader'] = '/^(CPU)\s*(In)\s*(Out)\s*(Uptime)\s*(Users)\s*(FPS)\s*(Players)$/i';
							$pattern['valveStats'] = '/\b(?P<CPU>\d{1,3}\.\d{1,2})\s*(?P<In>\d+\.\d{1,2})\s*(?P<Out>\d+\.\d{1,2})\s*(?P<Uptime>\d+)\s*(?P<MapChanges>\d+)\s*(?P<FPS>\d+\.\d{1,2})\s*(?P<Players>\d+)\s*(?P<Connects>\d+)\b/i';
							$pattern['valveHL1Stats'] = '/\b(?P<CPU>\d{1,3}\.\d{1,2})\s*(?P<In>\d+\.\d{1,2})\s*(?P<Out>\d+\.\d{1,2})\s*(?P<Uptime>\d+)\s*(?P<Users>\d+)\s*(?P<FPS>\d+\.\d{1,2})\s*(?P<Players>\d+)\s*\b/i';
							$pattern['codSeparator'] = '/^-+\s+.*$/';


							$rconTest = explode("\n", @$rconAnswer);
							$tableClose = false;
							$numStatusParams = 0; // Количество стобцов таблицы

							$tableHeaders = array();
							foreach ( $rconTest as $string ) {

								if (preg_match($pattern['info'], $string, $matches))
								{
									$rconResult .= '<strong>'.trim($matches['name']).'</strong>: '.$matches['value']."\n";
								}
								else
								if (preg_match($pattern['valveStatusHeader'], $string)
										or
								    preg_match($pattern['valveHL1StatsHeader'], $string)
								    	or
								    preg_match($pattern['codHeader'], $string)
								   )
								{
									$tableClose = true;
									$tableHeaders = preg_split("/\s+/", $string);
									$rconResult .='<table border="0" cellspacing="3">'."\n";
		       						$rconResult .= '<tr style="background-color: #555; color: white;">'."\n";


		       						foreach ( $tableHeaders as $statusTableHeader ) {
		       							$statusTableHeader = trim($statusTableHeader);
       									if ($statusTableHeader != '#'){
       										$rconResult .= '<th style="padding: 3px;">'.$statusTableHeader.'</th>';
       										$numStatusParams++;
       									}
									}

									$rconResult .= '</tr>';
								}
								else
								if (preg_match($pattern['valveStatsHeader'], $string, $matches))
								{
									$tableClose = true;
									$rconResult .='<table border="0" cellspacing="3">'."\n";
		       						$rconResult .= '<tr style="background-color: #555; color: white;">'."\n";

		       						unset($matches[0]);
		       						$numStatusParams = sizeof( $matches );

		       						for ( $index = 1; $index <= $numStatusParams; $index++ ) {
										$rconResult .= '<th style="padding: 3px;">'.$matches[$index].'</th>';

										// Заменить содержимое заголовков на имена, которые получаем в $pattern['valveStats']
										switch ( $matches[$index] ) {
											case 'In (KB/s)':
												$matches[$index] = 'In';
												break;

											case 'Out (KB/s)':
												$matches[$index] = 'Out';
												break;

											case 'Map changes':
												$matches[$index] = 'MapChanges';
												break;

											default:
												break;
										}

									}

		       						$rconResult .= '</tr>';
		       						$tableHeaders = $matches;
								}
								else
								if (preg_match($pattern['valveBot'], $string, $matches))
								{
									$rconResult .= '<tr style="background-color: #ccc;">'."\n";

									$rconResult .= "<td align='center'><strong>".$matches['userid']."</strong></td>\n";
									$rconResult .= "<td colspan='".($numStatusParams - 1)."'>".$matches['name']." (BOT) - ".$matches['state']."</td>\n";

									$rconResult .= "</tr>\n";
								}
								else
								if (preg_match($pattern['valvePlayer'], $string, $matches)
										or
									preg_match($pattern['valveL4DPlayer'], $string, $matches)
										or
									preg_match($pattern['valveHL1Stats'], $string, $matches)
										or
									preg_match($pattern['valveStats'], $string, $matches)
										or
									preg_match($pattern['codPlayer'], $string, $matches)
								   )
								{
									$rconResult .= '<tr style="background-color: #ccc;">'."\n";

									foreach ( $tableHeaders as $tableHeader ) {
       									if ($tableHeader == 'userid'){
       										$rconResult .= "<td align='center'><strong>".$matches[$tableHeader]."</strong></td>\n";
       									}
       									else
       									if ($tableHeader == 'name'){
       										if ($serverType == "cod"){
       											$rconResult .= "<td>".$this->codColorText($matches[$tableHeader])."</td>\n";
       										}
       										else
       										{
       											$rconResult .= "<td>".$matches[$tableHeader]."</td>\n";
       										}

       									}
       									else
       									if ($tableHeader != '#')
       									{
       										$rconResult .= "<td align='center'>".@$matches[$tableHeader]."</td>\n";
       									}
									}


									$rconResult .= "</tr>\n";
								}
								else
								if (trim($string) == '#end')
								{
									$rconResult .= '</table>';
									$tableClose = false;
								}
								else
								if (trim($string) != '' && !preg_match($pattern['codSeparator'], $string))
								{

									if ($tableClose === true){
										$rconResult .= '<tr style="background-color: #ccc;">'."\n";
										$rconResult .= "<td colspan='".($numStatusParams)."'>";
									}

									$rconResult .= $string."\n";

									if ($tableClose === true){
										$rconResult .= '</td></tr>'."\n";
									}
								}


							}

							if ($tableClose === true){
								$rconResult .= '</table>';
							}


							$this->set('rconResult',@$rconResult);
						}
						/* Конец форматирования */
						else
						{
							$this->set('rconResult',@$rconResult.@$rconAnswer);
						}

					}

					$rcon->disconnect();

				}
				catch (Exception $e){
					$this->set('rconResult',$e->getMessage());
				}
			}


		}
	}

	/*
	 * Автодополнение команды в строке rcon
	 */
	function rconAutoComplete ( $type = 'srcds' ) {
		$this->layout = 'ajax';

		if (isset($this->params['url']['term'])){
			// Делаем выборку из базы по запросу jQuery

			$conditions = array(
							     'command LIKE' => $this->params['url']['term'].'%'
						       );

			$termsKey = 'term_'.$this->params['url']['term'].'_'.$type;

			Cache::set(array('duration' => '+1 days'));

			switch ( $type ) {

				case 'srcds':
				case 'hlds':

					if (($terms = Cache::read($termsKey)) === false) {

						$this->loadModel('RconSrcdsCommand');
						$terms = $this->RconSrcdsCommand->find('all', array(
																	'conditions' => $conditions
														));
						Cache::set(array('duration' => '+1 days'));
						Cache::write($termsKey, $terms);
					}

					break;

				case 'cod':

					if (($terms = Cache::read($termsKey)) === false) {

						$this->loadModel('RconCodCommand');
						$terms = $this->RconCodCommand->find('all', array(
																		'conditions' => $conditions
															));

						Cache::set(array('duration' => '+1 days'));
						Cache::write($termsKey, $terms);
					}

					break;

				default:
					break;
			}


			// Готовим список для корректного преобразования в JSON
			if ( !empty($terms) ) {
				$termsList = array();

				foreach ($terms as $term):
					switch ( $type ) {
					case 'srcds':
					case 'hlds':
						$termsList[] = array ('command' => $term['RconSrcdsCommand']['command'],
										  'cheat' => $term['RconSrcdsCommand']['cheat'],
										  'desc' => $term['RconSrcdsCommand']['desc']);
						break;

					case 'cod':
						$termsList[] = array ('command' => $term['RconCodCommand']['command'],
										  'cheat' => $term['RconCodCommand']['cheat'],
										  'desc' => $term['RconCodCommand']['desc']);
						break;

					default:
						break;
				}


				endforeach;

				$this->set('list', $termsList);
			}
		}
	}

	// Исполнение автоматических команд через RCON
	function setMapRcon ( $id = null, $command = null ){
		$this->DarkAuth->requiresAuth();
		if ($this->checkRights($id)){

			$this->set('id', $id);

			Cache::set(array('duration' => '+2 minutes'));
			if (($maps = Cache::read('mapsRcon'.$id)) === false) {

				$mapsRcon = $this->rconResult( $id, 'maps *', false, 'clean');

				if ($mapsRcon != false)
				{
					$maps = array();

					foreach (explode("\n", @$mapsRcon) as $string) {

						$pattern = '/PENDING\:\s+\(fs\)\s+(?P<map>.+)\.bsp/';

						if (preg_match($pattern, $string, $matches))
						{
							$maps[$matches['map']] = ucfirst($matches['map']);
						}

					}

					ksort($maps);
				}
				else
				{
					$this->render();
					return false;
				}

				Cache::set(array('duration' => '+2 minutes'));
				Cache::write('mapsRcon'.$id, $maps);
			}

			$this->set('maps', $maps);

			// Выполнение команды RCON
			if (!is_null($command) or !empty($this->data))
			{
				if (!is_null($command))
				{
					unset($command);
					//$answer = $this->rconResult( $id, $command, false, 'clean');
					$this->Session->setflash('Свободные команды заблокированы', 'flash_error');
				}
				else
				if (!empty($this->data['Server']['maps']))
				{
					$command = 'changelevel '.$this->data['Server']['maps'];

					$answer = $this->rconResult( $id, $command, false, 'clean');

					if ($answer !== false)
					{
						if ($answer != '')
						{
							$this->Session->setflash("Ответ сервера: <br/>".$answer, 'flash_success');
						}
						else
						{
							$this->Session->setflash("Команда на смену карты отправлена успешно.<br/>".
													 "Смотрите статус сервера и логи, чтобы увидеть результат.", 'flash_success');
						}
					}
					else
					{
						$this->Session->setflash('Возникла ошибка при выполнении команды. '.
												 'Проверьте наличие и правильность RCON-пароля.', 'flash_error');
					}
				}
				else
				{
					$this->Session->setflash('Неизвестная команда', 'flash_error');
				}

				$this->set('refresh', true);
			}


		}
	}

	// Включение/отключение контроля сервера без пароля
	function setControlToken( $id = null ){
		$this->DarkAuth->requiresAuth();
		if ($this->checkRights($id)){
			$this->loadmodel('ServerClean');
			$this->ServerClean->id = $id;
			$server = $this->ServerClean->read();
			if ($server['ServerClean']['controlByToken'] == 0){

				$consonantes = "AaBbCcDdEeFfGgHhIiJjKkLlMmNnPpQqRrSsTtVvWwXxYyZz123456789";
				$r = '';
				for ($i = 0; $i < 64; $i++) {
						$r .= $consonantes{rand(0, strlen($consonantes) - 1)};
				}
				$r = md5($r);

				$server['ServerClean']['controlByToken'] = '1';
				$server['ServerClean']['controlToken']   = $r;

				if ($this->ServerClean->save($server)){
					$this->Session->setFlash("Управление по токену включено успешно.", 'flash_success');
				}
				else
				{
					$this->Session->setFlash("Произошла ошибка. Попробуйте позднее. ".mysql_error(), 'flash_error');
				}
			}
			else
			if ($server['ServerClean']['controlByToken'] == 1){
				$server['ServerClean']['controlByToken'] = '0';
				$server['ServerClean']['controlToken']   = NULL;

				if ($this->ServerClean->save($server)){
					$this->Session->setFlash("Управление по токену запрещено.", 'flash_success');
				}
				else
				{
					$this->Session->setFlash("Произошла ошибка. Попробуйте позднее. ".mysql_error(), 'flash_error');
				}

			}
		}

		$this->redirect(array('action'=>'editStartParams',$id));

	}
	// Управление сервером без пароля
	function controlByToken( $token = null) {
		$this->layout = 'simple';
		if ($token != null){
			$this->loadmodel('ServerTemplateProtocol');
			$server = $this->ServerTemplateProtocol->find('first', array(
																		  'recursive' => '2',
																		  'conditions' => array(
																		  						'controlToken' => $token
																		  							)));
			if ($server){
				$ip = $server['ServerTemplateProtocol']['address'];
				$port = $server['ServerTemplateProtocol']['port'];


				if ($server['GameTemplate'][0]['Protocol'][0]['name'] == "halflife"){


					try {
						$handle = new GoldSrcServer(new InetAddress($ip), $port);
						$handle->initialize();

					}
					catch (Exception $e)
					{
						//pr($e);
					}

					try {

						$status = $handle->getServerInfo();

					}
					catch (Exception $e)
					{
						$status = false;
						//pr($e);
					}

				}
				else
				if ($server['GameTemplate'][0]['Protocol'][0]['name'] == "source"){

					// Попытка подключиться к серверу
					try {
						$handle = new SourceServer(new InetAddress($ip), $port);
						$handle->initialize();
					}
					catch (Exception $e)
					{
						//pr($e);
					}

					try {

						$status = $handle->getServerInfo();

					}
					catch (Exception $e)
					{
						$status = false;
						//pr($e);
					}


				}
				else
				if ($server['GameTemplate'][0]['Protocol'][0]['name'] == "quake3"){

					// Попытка подключиться к серверу
					$handle = new COD4ServerStatus($ip, $port);

				    if ($handle->getServerStatus()){
				    	$handle->parseServerData();
				    	$serverInfo = $handle->returnServerData();
						$players = $handle->returnPlayers();
						$pings   = $handle->returnPings();
						$scores  = $handle->returnScores();
						// Обработать игроков и посчитать их количество
						$bots = 0;
						$players = 0;
						$playerInfo = array();
						foreach ( $handle->returnPlayers() as $i => $playerName ) {
		       				if (preg_match('/^bot[\d]{1,5}/i', $playerName) && $pings[$i] == 999){
		       					$bots++;
		       				}
		       				else
		       				{
		       					$players++;
		       				}
						}

						$status =  $serverInfo;

						$status['playerNumber'] = $players;
						$status['botNumber']  = $bots;
						$status['maxPlayers'] = $serverInfo['sv_maxclients'];

						// Раскрасим некоторые текстовки, как в COD
						$status['serverName'] = $this->codColorText($serverInfo['sv_hostname']).
												'<br/>'.$this->codColorText($serverInfo['_Mod']);
						$status['mapName'] = $serverInfo['mapname'];
						$status['gameVersion'] = $serverInfo['shortversion'];

				    }

				}

				if (!empty($status)){

					$status['status'] = 'running';

				}
				else
				{
					$status['status'] = 'stoped';
				}

				$status['id'] = $server['ServerTemplateProtocol']['id'];
				$status['ip'] = $ip;
				$status['port'] = $port;
				$status['gameshort'] = $server['GameTemplate'][0]['name'];
				$status['gamefull'] = $server['GameTemplate'][0]['longname'];

				$this->set('token',$token);
				$this->set('status',$status);

				if (!empty($server['GameTemplate'][0]['current_version']))
			    {

			    	$this->set('currentVersion', $server['GameTemplate'][0]['current_version']);
			    }
			}
			else
			{
				$this->Session->setFlash('Неверный токен', 'flash_error');
			}
		}



	}

	function accessInfo( $id = null ) {
		/* Вывод всех видов доступа к серверу
		 */
		if($this->checkRights($id)){
			// Генерация нового одноразового хэша клиента
			$token=md5(rand(23658,8000064000).time());
			$this->Server->User->id = $this->DarkAuth->getUserId();
			$this->Server->User->saveField('tokenhash',$token);

			$this->Server->unbindModel(array(
												'hasAndBelongsToMany' => array(
																	'Mod',
																	'Plugin',
																	'Service',
																	'Order',
																	'RootServer',
																	'VoiceMumbleParam',
																	'RadioShoutcastParam'
														)));
			$this->Server->id = $id;
			$this->data = $this->Server->read();
		}


	}

	function result(){
		// Функция пустышка для вывода результатов операций
	}

}

?>
