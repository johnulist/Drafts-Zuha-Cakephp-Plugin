<?php
App::uses('ModelBehavior', 'Model');

/**
 * Draftable Behavior class file.
 *
 * Adds ability to save drafts and revisions for any model. 
 * 
 * Usage is :
 * Attach behavior to a model, and when you save 
 *
 * @filesource
 * @author			Richard Kersey
 * @copyright       RazorIT LLC
 * @license			http://www.opensource.org/licenses/mit-license.php The MIT License
 * @link            https://github.com/zuha/Drafts-Zuha-Cakephp-Plugin
 */
class DraftableBehavior extends ModelBehavior {

/**
 * Behavior settings
 * 
 * @access public
 * @var array
 */
	public $settings = array();

/**
 * The full results of Model::find() that are modified and saved
 * as a new copy.
 *
 * @access public
 * @var array
 */
	public $record = array();

/**
 * Default values for settings.
 *
 * - recursive: whether to copy hasMany and hasOne records
 * - habtm: whether to copy hasAndBelongsToMany associations
 * - stripFields: fields to strip during copy process
 * - ignore: aliases of any associations that should be ignored, using dot (.) notation.
 * will look in the $this->contain array.
 *
 * @access private
 * @var array
 */
    protected $defaults = array(
		'triggerField' => 'draft',
		'modelAlias' => null, // changed to $Model->alias in setup()
		'foreignKeyName' => null,
		'reviseDateField' => 'revise_to_date',
		);


/**
 * Configuration method.
 *
 * @param object $Model Model object
 * @param array $config Config array
 * @access public
 * @return boolean
 */
    public function setup($Model, $config = array()) {
    	$this->settings = array_merge($this->defaults, $config);
		$this->modelName = !empty($this->settings['modelAlias']) ? $this->settings['modelAlias'] : $Model->alias;
		$this->foreignKey =  !empty($this->settings['foreignKeyName']) ? $this->settings['foreignKeyName'] : $Model->primaryKey;
		
    	return true;
	}

/**
 * Before save method.
 *
 * If the data array object contains a value $data['Draft']['status'] = 1, then save draft
 *
 * @param object $Model model object
 * @param mixed $id String or integer model ID
 * @access public
 * @return boolean
 */
	public function beforeSave($Model, $id) {
		if (!empty($Model->data[$this->modelName][$this->settings['triggerField']])) {
			if ($Model->data[$this->modelName][$this->settings['triggerField']] == 'revise') {
				// update data to an older version as opposed to saving a draft
				$Model->data = $this->saveRevision($Model, $this->modelName, $Model->data[$this->modelName][$this->foreignKey], $Model->data[$this->modelName][$this->settings['reviseDateField']], true); 
				return true;
			} else {
				// save to the drafts table and replace the Model data so that it does not save
				if (!empty($Model->data[$this->modelName][$this->foreignKey])) {
					// ex. array('Article => array('id' => '911923-1-810291-2-1'))
					// this is coming from an edit save so we can just intercept here
					try {
						$this->saveDraft($Model);
						$Model->data = $this->_liveData($Model);
						unset($Model->data[$this->modelName][$this->settings['triggerField']]);
						return true;
					} catch (Exception $e) {
						debug($e->getMessage());
						break;
					}
				} else {
					return true;
				}
			}
		}
		return true;
	}
	

/**
 * After save method.
 *
 * If the data array object contains a value $data['Draft']['status'] = 1, then save draft.
 *
 * @param object $Model model object
 * @param mixed $id String or integer model ID
 * @access public
 * @return boolean
 */
	public function afterSave($Model, $created) {
		if (!empty($Model->data[$this->modelName][$this->settings['triggerField']]) && !empty($Model->data[$this->modelName][$this->foreignKey])) { // now 'id' exists in afterSave
			try {
				$this->saveDraft($Model);
			} catch (Exception $e) {
				debug($e->getMessage());
				break;
			}
		}
	}
	

/**
 * After delete method
 * 
 * Delete all of the drafts if a record is deleted
 */
 	public function afterDelete($Model) {
			try {
				$Model->bindModel(array('hasMany' => array(
					'Draft' => array(
						'className' => 'Drafts.Draft',
						'foreignKey' => $this->foreignKey,
						'conditions' => array('Draft.model' => $this->modelName),
						'fields' => '',
						'dependent' => true,
					))), false);
				return $Model->Draft->deleteAll(array('Draft.model' => $this->modelName, 'Draft.foreign_key' => $Model->id));
			} catch (Exception $e) {
				debug($e->getMessage());
				break;
			}
	}
	
	
/**
 * Save draft method.
 *
 * If the data array object contains a value $data['Draft']['status'] = 1, then save draft.
 *
 * @param object $Model model object
 * @param mixed $id String or integer model ID
 * @access public
 * @return boolean
 */
	public function saveDraft($Model) {
		if (!empty($Model->data[$this->modelName][$this->foreignKey])) {
			try {
				$Model->bindModel(array('hasMany' => array(
					'Draft' => array(
						'className' => 'Drafts.Draft',
						'foreignKey' => $this->foreignKey,
						'conditions' => array('Draft.model' => $this->modelName),
						'fields' => '',
						'dependent' => true,
					))), false);
				$draft['Draft']['model'] = $this->modelName;
				$draft['Draft']['foreign_key'] = $Model->data[$this->modelName][$this->foreignKey];
				$draft['Draft']['value'] = serialize($Model->data);
				return $Model->Draft->save($draft);
			} catch (Exception $e) {
				debug($e->getMessage());
				break;
			}
		} else {
			throw new Exception(__('foreignKeyName is required (normally id)'));
		}
	}
	
	
/**
 * Save revision method.
 *
 * Revert a record to an earlier version, saving the current version as a draft
 *
 * @param object $Model model object
 * @param mixed $id String or integer model ID
 * @access public
 * @return boolean
 */
	public function saveRevision($Model, $modelName = null, $foreignKey = null, $date = null, $returnOnly = false) {
		$modelName = !empty($modelName) ? $modelName : $this->modelName;
		$foreignKey = !empty($foreignKey) ? $foreignKey : $Model->data[$this->modelName][$this->foreignKey];
		$date = !empty($date) ? $date : $Model->data[$this->modelName][$this->settings['reviseDateField']];
		
		$Model->bindModel(array('hasMany' => array(
			'Draft' => array(
				'className' => 'Drafts.Draft',
				'foreignKey' => $this->foreignKey,
				'conditions' => array('Draft.model' => $this->modelName),
				'fields' => '',
				'dependent' => true,
			))), false);
		$revision = $Model->Draft->find('first', array(
			'conditions' => array(
				'Draft.model' => $modelName, 
				'Draft.foreign_key' => $foreignKey,
				'Draft.created' => $date,
				),
			));		
		
		if (!empty($returnOnly)) {
			return Set::merge($Model->data, unserialize($revision['Draft']['value']));
		} else {
			return $Model->save(unserialize($revision['Draft']['value']));
		}
	}
	
	
/**
 * Save live method
 * 
 * Since we saved a draft, we sometimes need to resave the existing record so that beforeSave will still return true.
 *
 * @param object
 * @access protected
 * @return boolean
 */
 	protected function _liveData($Model) {
		$original = $Model->find('first', array(
			'conditions' => array(
				$this->foreignKey => $Model->data[$this->modelName][$this->foreignKey]
				),
			));
		return Set::merge($Model->data, $original);
	}


/**
 * After find method.
 *
 * @param object $Model model object
 * @param mixed $id String or integer model ID
 * @access public
 * @return boolean
 */
	public function afterFind($Model, $id) {
		
	}
	
}