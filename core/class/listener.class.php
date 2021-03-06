<?php

/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */

/* * ***************************Includes********************************* */
require_once __DIR__ . '/../../core/php/core.inc.php';

class listener {
	/*     * *************************Attributs****************************** */

	private $id;
	private $event;
	private $class;
	private $function;
	private $option;

	/*     * ***********************Méthodes statiques*************************** */

	public static function all() {
		$sql = 'SELECT ' . DB::buildField(__CLASS__) . '
                FROM listener';
		return DB::Prepare($sql, array(), DB::FETCH_TYPE_ALL, PDO::FETCH_CLASS, __CLASS__);
	}

	public static function byId($_id) {
		$value = array(
			'id' => $_id,
		);
		$sql = 'SELECT ' . DB::buildField(__CLASS__) . '
                FROM listener
                WHERE id=:id';
		return DB::Prepare($sql, $value, DB::FETCH_TYPE_ROW, PDO::FETCH_CLASS, __CLASS__);
	}

	public static function byClass($_class) {
		$value = array(
			'class' => $_class,
		);
		$sql = 'SELECT ' . DB::buildField(__CLASS__) . '
                FROM listener
                WHERE class=:class';
		return DB::Prepare($sql, $value, DB::FETCH_TYPE_ALL, PDO::FETCH_CLASS, __CLASS__);
	}

	public static function byClassAndFunction($_class, $_function, $_option = '') {
		$value = array(
			'class' => $_class,
			'function' => $_function,
		);
		$sql = 'SELECT ' . DB::buildField(__CLASS__) . '
                FROM listener
                WHERE class=:class
                    AND function=:function';
		if ($_option != '') {
			$_option = json_encode($_option, JSON_UNESCAPED_UNICODE);
			$value['option'] = $_option;
			$sql .= ' AND `option`=:option';
		}
		return DB::Prepare($sql, $value, DB::FETCH_TYPE_ROW, PDO::FETCH_CLASS, __CLASS__);
	}

	public static function byClassFunctionAndEvent($_class, $_function, $_event) {
		$value = array(
			'class' => $_class,
			'function' => $_function,
			'event' => $_event,
		);
		$sql = 'SELECT ' . DB::buildField(__CLASS__) . '
                FROM listener
                WHERE class=:class
                    AND function=:function
                    AND event=:event';
		return DB::Prepare($sql, $value, DB::FETCH_TYPE_ALL, PDO::FETCH_CLASS, __CLASS__);
	}

	public static function removeByClassFunctionAndEvent($_class, $_function, $_event, $_option = '') {
		$value = array(
			'class' => $_class,
			'function' => $_function,
			'event' => $_event,
		);
		$sql = 'DELETE FROM listener
                WHERE class=:class
                    AND function=:function
                    AND event=:event';
		if ($_option != '') {
			$_option = json_encode($_option, JSON_UNESCAPED_UNICODE);
			$value['option'] = $_option;
			$sql .= ' AND `option`=:option';
		}
		DB::Prepare($sql, $value, DB::FETCH_TYPE_ROW);
	}

	public static function searchEvent($_event) {
		if (strpos($_event, '#') !== false) {
			$value = array(
				'event' => '%' . $_event . '%',
			);
		} else {
			$value = array(
				'event' => '%#' . $_event . '#%',
			);
		}
		$sql = 'SELECT ' . DB::buildField(__CLASS__) . '
                FROM listener
                WHERE `event` LIKE :event';
		return DB::Prepare($sql, $value, DB::FETCH_TYPE_ALL, PDO::FETCH_CLASS, __CLASS__);
	}

	public static function check($_event, $_value, $_datetime) {
		$listeners = self::searchEvent($_event);
		if (count($listeners) > 0) {
			foreach ($listeners as $listener) {
				$listener->run(str_replace('#', '', $_event), $_value, $_datetime);
			}
		}
	}

	public static function backgroundCalculDependencyCmd($_event) {
		$cmd = __DIR__ . '/../php/jeeListener.php';
		$cmd .= ' event_id=' . $_event;
		system::php($cmd . ' >> /dev/null 2>&1 &');
	}

	public function getName() {
		if ($this->getClass() != '') {
			return $this->getClass() . '::' . $this->getFunction() . '()';
		}
		return $this->getFunction() . '()';
	}

	/*     * *********************Méthodes d'instance************************* */

	public function run($_event, $_value, $_datetime = null) {
		$option = array();
		if (count($this->getOption()) > 0) {
			$option = $this->getOption();
		}
		if (isset($option['background']) && $option['background'] == false) {
			$this->execute($_event, $_value);
		} else {
			$cmd = __DIR__ . '/../php/jeeListener.php';
			$cmd .= ' listener_id=' . $this->getId() . ' event_id=' . $_event . ' "value=' . escapeshellarg($_value) . '"';
			if ($_datetime !== null) {
				$cmd .= ' "datetime=' . escapeshellarg($_datetime) . '"';
			}
			system::php($cmd . ' >> ' . log::getPathToLog('listener_execution') . ' 2>&1 &');
		}
	}

	public function execute($_event, $_value, $_datetime = '') {
		try {
			$option = array();
			if (count($this->getOption()) > 0) {
				$option = $this->getOption();
			}
			$option['event_id'] = $_event;
			$option['value'] = $_value;
			$option['datetime'] = $_datetime;
			$option['listener_id'] = $this->getId();
			if ($this->getClass() != '') {
				$class = $this->getClass();
				$function = $this->getFunction();
				if (class_exists($class) && method_exists($class, $function)) {
					$class::$function($option);
				} else {
					log::add('listener', 'debug', __('[Erreur] Classe ou fonction non trouvée ', __FILE__) . $this->getName());
					$this->remove();
					return;
				}
			} else {
				$function = $this->getFunction();
				if (function_exists($function)) {
					$function($option);
				} else {
					log::add('listener', 'error', __('[Erreur] Non trouvée ', __FILE__) . $this->getName());
					return;
				}
			}
		} catch (Exception $e) {
			log::add(init('plugin_id', 'plugin'), 'error', $e->getMessage());
		}
	}

	public function preSave() {
		if ($this->getFunction() == '') {
			throw new Exception(__('La fonction ne peut pas être vide', __FILE__));
		}
	}

	public function save($_once = false) {
		if ($_once) {
			self::removeByClassFunctionAndEvent($this->getClass(), $this->getFunction(), $this->event, $this->getOption());
		}
		return DB::save($this);
	}

	public function remove() {
		return DB::remove($this);
	}

	public function emptyEvent() {
		$this->setEvent(array());
	}

	public function addEvent($_id, $_type = 'cmd') {
		$event = $this->getEvent();
		if (!is_array($event)) {
			$event = array();
		}
		if ($_type == 'cmd') {
			$id = str_replace('#', '', $_id);
		}
		if (!in_array('#' . $id . '#', $event)) {
			$event[] = '#' . $id . '#';
		}
		$this->setEvent($event);
	}

	/*     * **********************Getteur Setteur*************************** */

	public function getId() {
		return $this->id;
	}

	public function getEvent() {
		return json_decode($this->event, true);
	}

	public function getClass() {
		return $this->class;
	}

	public function getFunction() {
		return $this->function;
	}

	public function getOption() {
		return json_decode($this->option, true);
	}

	public function setId($id) {
		$this->id = $id;
		return $this;
	}

	public function setEvent($event) {
		$this->event = json_encode($event, JSON_UNESCAPED_UNICODE);
		return $this;
	}

	public function setClass($class) {
		$this->class = $class;
		return $this;
	}

	public function setFunction($function) {
		$this->function = $function;
		return $this;
	}

	public function setOption($option) {
		$this->option = json_encode($option, JSON_UNESCAPED_UNICODE);
		return $this;
	}

}
