<?php

namespace App\CLAgg;

use App\CLAgg\Utils;

/**
 * @author Robert S Kraig
 * @version 0.7
 *
 */
class Scraper {

	private $_record_list = array();

	public function __construct($request_conf, $include, $locations, $fields)
	{
		$this->initialize($request_conf, $include, $locations, $fields);
	}

	/**
	 * Will assemble search queries onto existing stored CL urls
	 * @param array $request_conf list of post arguments
	 * @param array $fields list of accepted fields
	 * @param array $location_url_param_list list of urls to search CL with
	 * @return array
	 */
	private static function _append_and_build_search_query(array $request_conf, array $fields, array $location_url_param_list)
	{
		$tmp_arr = array();
		foreach($fields as $field)
		{
			if(isset($request_conf[$field['argName']]))
			{
				$tmp_arr[$field['argName']] = $request_conf[$field['argName']];
			}
		}

		$tmp_arr['format'] = 'rss';

		$request_conf_str = http_build_query($tmp_arr);
		foreach($location_url_param_list as $key=> $val)
		{
			$location_url_param_list[$key]['url'].="{$request_conf_str}";
		}

		return $location_url_param_list;
	}

	/**
	 * Read the RSS XML data from CL and turn it into a usable array datastructure
	 * @param array $location craigslist section
	 * @return array
	 */
	private static function _get_records(array $location)
	{
		$string = Utils::getFileCache($location['url']);
		if(!$string) return array();

		$xml = simplexml_load_string($string, 'SimpleXMLElement', LIBXML_NOCDATA);

		$search_items = array();
		foreach($xml->item as $item)
		{
			$info = get_object_vars($item);
			$dc_nodes = $item->children('http://purl.org/dc/elements/1.1/');
			$dc = get_object_vars($dc_nodes);
			$data = $info + $dc;
			unset($data['description']);
			$search_items[] = array_merge($data, array(
				'location'=>$location['partial']
			));
		}

		return $search_items;
	}

	/**
	 * Pulls all the records and assembles them into a data structure suitable for a website
     * @param array $request_conf
	 * @param array $include craigslist sections
	 * @param array $locations list of avaliable sites to query against
	 * @param array $fields fields to initialize
	 */
	private function initialize(array $request_conf, array $include, array $locations, array $fields)
	{
		$search_items = array();
		$include = str_replace(array(".","+"), array('\\.',"(.+)"), implode('|', $include));

		$locations = self::_append_and_build_search_query($request_conf, $fields, $locations);
		foreach($locations as $place)
		{
			if(preg_match("/({$include})/", $place['url']))
			{
				$list = self::_get_records($place);
				$search_items = array_merge($search_items, $list);
			}
		}

		$this->_record_list = self::_processData($search_items);
	}

	/**
	 * Process CL RSS data structure into something well organized
	 * @param array $search_items
	 * @return array
	 */
	private static function _processData(array $search_items)
	{
		$data = array();
		foreach($search_items as $item)
		{
			$date = $item['date'];
			$date_timestamp = strtotime($date);
			$uniqu_group_hash = $date_timestamp;
			$data[$uniqu_group_hash] = $item;
		}

		uksort($data, function($a, $b)
		{
			if($a == $b) return 0;
			return ($a > $b)?1:-1;
		});

		$regroup_list = array();
		foreach($data as $date_timestamp => $item)
		{
			$group_hash = date('M-j-y', $date_timestamp);
			$regroup_list[$group_hash]['timestamp'] = $date_timestamp;
			$regroup_list[$group_hash]['date'] = date('M jS', $date_timestamp);
			$regroup_list[$group_hash]['records'][$item['location']][] = $item;
		}

		return array_reverse($regroup_list);
	}

	public function getRecords()
	{
		return $this->_record_list;
	}
}