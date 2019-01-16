<?php
/**
 * SEO
 *
 * @link      https://ethercreative.co.uk
 * @copyright Copyright (c) 2018 Ether Creative
 */

namespace ether\seo\migrations;

use craft\db\Migration;
use craft\db\Query;
use ether\seo\fields\SeoField;
use ether\seo\jobs\UpgradeSeoDataJob;

/**
 * Class m190114_152300_upgrade_to_new_data_format
 *
 * @author  Ether Creative
 * @package ether\seo\migrations
 */
class m190114_152300_upgrade_to_new_data_format extends Migration
{

	public function safeUp ()
	{
		$craft = \Craft::$app;

		// 1. For each SEO field, update the settings to the template
		$fields = $this->_getAllSeoFields();

		$fields = array_map(function ($field) use ($craft) {
			return $craft->fields->createField($field);
		}, $fields);
		$suffixesByFieldHandle = [];

		$siteName = $craft->sites->primarySite->name;

		/** @var SeoField $field */
		foreach ($fields as $field) if ($field->titleSuffix !== null)
		{
			// If `- Current Prefix` contains the site name, replace with `{{siteName}}`
			$suffix = $field->titleSuffix;

			if (strpos($suffix, $siteName))
				$suffix = str_replace($siteName, '{{siteName}}', $suffix);

			$field->title = [
				[
					'key'      => '1',
					'template' => '{title}',
					'locked'   => false,
				]
			];

			if ($suffix !== '')
			{
				$field->title[] = [
					'key'      => '2',
					'template' => ' ' . $suffix,
					'locked'   => true,
				];

				$suffixesByFieldHandle[$field->handle] = ' ' . $field->titleSuffix;
			}
			else
			{
				$suffixesByFieldHandle[$field->handle] = '';
			}

			// '[{title}] [- Current Prefix]' (or flipped if suffixAsPrefix is true)
			if ($field->suffixAsPrefix && $suffix !== '')
			{
				$field->title = array_reverse($field->title, false);
				$field->title[0]['key'] = '1';
				$field->title[1]['key'] = '2';
				$field->title[1]['template'] = $suffix . ' ';

				$suffixesByFieldHandle[$field->handle] = $field->titleSuffix . ' ';
			}

			$craft->fields->saveField($field);
		}

		// 2. Queue re-save of all elements that have an SEO field
		$layouts = $this->_getAllElementTypesAndIdsThatHaveSEOFields();

		foreach ($layouts as $type => $data)
		{
			$craft->queue->push(new UpgradeSeoDataJob([
				'elementType' => $type,
				'criteria' => [
					'id' => $data['ids']
				],
				'handle' => $data['handle'],
				'suffix' => $suffixesByFieldHandle[$data['handle']],
			]));
		}
	}

	public function safeDown ()
	{
		return false;
	}

	// Helpers
	// =========================================================================

	private function _getAllSeoFields ()
	{
		return (new Query())
			->select(
				[
					'fields.id',
					'fields.dateCreated',
					'fields.dateUpdated',
					'fields.groupId',
					'fields.name',
					'fields.handle',
					'fields.context',
					'fields.instructions',
					'fields.translationMethod',
					'fields.translationKeyFormat',
					'fields.type',
					'fields.settings',
				]
			)
			->from(['{{%fields}} fields'])
			->where(['type' => SeoField::class])
			->orderBy(['fields.name' => SORT_ASC, 'fields.handle' => SORT_ASC])
			->all();
	}

	private function _getAllElementTypesAndIdsThatHaveSEOFields ()
	{
		$results = (new Query())
			->select(['el.type', 'el.id', 'fields.handle'])
			->from(['{{%fields}} fields'])
			->innerJoin(
				'{{%fieldlayoutfields}} flf',
				'[[flf.fieldId]] = [[fields.id]]'
			)
			->innerJoin(
				'{{%fieldlayouts}} fl',
				'[[fl.id]] = [[flf.layoutId]]'
			)
			->innerJoin(
				'{{%elements}} el',
				'[[el.fieldLayoutId]] = [[flf.layoutId]] AND [[el.type]] = [[fl.type]]'
			)
			->where(['fields.type' => SeoField::class])
			->all();

		return array_reduce($results, function ($a, $b) {
			if (!array_key_exists($b['type'], $a))
				$a[$b['type']] = [
					'ids' => [],
					'handle' => $b['handle'],
				];

			$a[$b['type']]['ids'][] = $b['id'];

			return $a;
		}, []);
	}

}