<?php

class EmailReminder_NotificationSchedule extends DataObject
{

    /**
     * @var string
     */ 
    private static $default_data_object = 'Member';

    /**
     * @var string
     */     
    private static $default_date_field = '';

    /**
     * @var string
     */     
    private static $default_email_field = '';

    /**
     * @var string
     */ 
    private static $mail_out_class = 'EmailReminder_DailyMailOut';

    private static $singular_name = 'Email Reminder Schedule';
    public function i18n_singular_name()
    {
        return self::$singular_name;
    }

    private static $plural_name = 'Email Reminder Schedules';
    public function i18n_plural_name()
    {
        return self::$plural_name;
    }

    private static $db = array(
        'DataObject' => 'Varchar(100)',     
        'EmailField' => 'Varchar(100)',     
        'DateField' => 'Varchar(100)',      
        'Days' => 'Int',                    
        'RepeatDays' => 'Int',                    
        'BeforeAfter' => "Enum('before,after','before')",
        'EmailFrom' => 'Varchar(100)',      
        'EmailSubject' => 'Varchar(100)',   
        'Content' => 'HTMLText', 
        'Disable' => 'Boolean',
        'SendTestTo' => 'Text'
    );


    private static $has_many = array(
        'EmailsSent' => 'EmailReminder_EmailRecord'
    );

    private static $summary_fields = array(
        'EmailSubject',
        'Days'
    );

    private static $field_labels = array(
        'DataObject' => 'Class/Table',
        'Days' => 'Days from Expiry',
        'RepeatDays' => 'Repeat cycle days'
    );

    function populateDefaults(){
        parent::populateDefaults();
        $this->DataObject = $this->Config()->get('default_data_object');
        $this->EmailField = $this->Config()->get('default_email_field');
        $this->DateField = $this->Config()->get('default_date_field');
        $this->Days = 7;
        $this->RepeatDays = 300;
        $this->BeforeAfter = 'before';
        $this->EmailFrom = Config::inst()->get('Email', 'admin_email');
        $this->EmailSubject = 'Your memberships expires in [days] days';
    }

    public function getCMSFields()
    {
        $fields = parent::getCMSFields();

        $emailsSentField = $fields->dataFieldByName('EmailsSent');
        $fields->removeFieldFromTab('Root', 'EmailsSent');
        
        $fields->addFieldToTab(
            'Root.Main',
            CheckboxField::create('Disable')
        );
        $fields->addFieldToTab(        
            'Root.Main',
            $dataObjecField = DropdownField::create(
                'DataObject',
                'Table/Class Name',
                $this->dataObjectOptions()
            )
            ->setRightTitle('Type a valid table/class name')
        );
        if($this->Config()->get('default_data_object')) {
            $fields->replaceField('DataObject', $dataObjecField->performReadonlyTransformation());
        }



        $fields->addFieldToTab(
            'Root.Main',
            $emailFieldField = DropdownField::create(
                'EmailField',
                'Email Field',
                $this->emailFieldOptions()
            )
            ->setRightTitle('Select the field that will contain a valid email address')
            ->setEmptyString('[ Please select ]')
        );
        if($this->Config()->get('default_email_field')) {
            $fields->replaceField('EmailField', $emailFieldField->performReadonlyTransformation());
        }

        $fields->addFieldToTab(
            'Root.Main',
            $dateFieldField = DropdownField::create(
                'DateField',
                'Date Field',
                $this->dateFieldOptions()
            )
            ->setRightTitle('Select a valid Date field to calculate when reminders should be sent')
            ->setEmptyString('[ Please select ]')
        );
        if($this->Config()->get('default_date_field')) {
            $fields->replaceField('DateField', $dateFieldField->performReadonlyTransformation());
        }

        $fields->removeFieldsFromTab(
            'Root.Main',
            array('Days','BeforeAfter', 'RepeatDays')
        );
        $fields->addFieldsToTab(
            'Root.Main',
            array(
                NumericField::create('Days', 'Days')
                    ->setRightTitle('How many days in advance (before) or in arrears (after) of the expiration date should this email be sent?'),
                NumericField::create('RepeatDays', 'Repeat Cycle Days')
                    ->setRightTitle('Number of days after which the cycle can be repeated (e.g. 300 days)'),
                DropdownField::create('BeforeAfter', 'Before / After Expiration', array('before' => 'before', 'after' => 'after'))
                    ->setRightTitle('Are the days listed above before or after the actual expiration date.')
            )
        );
        $fields->addFieldsToTab(
            'Root.EmailContent',
            array(
                TextField::create('EmailFrom', 'Email From Address')
                    ->setRightTitle('The email from address, eg: "My Company &lt;info@example.com&gt;"'),
                $subjectField = TextField::create('EmailSubject', 'Email Subject Line')
                    ->setRightTitle('The subject of the email'),
                $contentField = HTMLEditorField::create('Content', 'Email Content')
                    ->SetRows(20)
            )
        );
        if($obj = $this->getReplacerObject()) {
            $html = $obj->replaceHelpList($asHTML = true);
            $subjectField->setRightTitle($html);
            $contentField->setRightTitle($html);
        }
        $fields->addFieldsToTab(
            'Root.Sent',
            array(        
                TextareaField::create('SendTestTo', 'Send test email to ...')
                    ->setRightTitle('
                        Separate emails by commas
                        '
                    )
                    ->SetRows(3)
            )
        );
        if($emailsSentField) {
            $config = $emailsSentField->getConfig();
            $config->removeComponentsByType('GridFieldAddExistingAutocompleter');
            $fields->addFieldToTab(
                'Root.Sent',
                $emailsSentField
            );
        }
        return $fields;
    }

    /**
     * @return array
     */ 
    protected function dataObjectOptions(){
        return ClassInfo::subclassesFor("DataObject");
    }

    /**
     * @return array
     */ 
    protected function emailFieldOptions(){
        return $this->getFieldsFromDataObject(array('Varchar', 'Email'));
    }

    /**
     * @return array
     */ 
    protected function dateFieldOptions(){
        return $this->getFieldsFromDataObject(array('Date'));
    }


    protected function getFieldsFromDataObject($fieldTypeMatchArray = array()){
        $allOptions = DataObject::database_fields($this->DataObject);
        $array = array();
        if($this->hasValidDataObject()) {
            $object = Injector::inst()->get($this->DataObject);
            if($object) {
               $fieldLabels = $object->fieldLabels();
                foreach($allOptions as $fieldName => $fieldType) {
                    foreach($fieldTypeMatchArray as $matchString) {
                        if(strpos($fieldType, $matchString) !== false) {
                            if(isset($fieldLabels[$fieldName])) {
                                $label = $fieldLabels[$fieldName];
                            } else {
                                $label = $fieldName;
                            }
                            $array[$fieldName] = $label;
                        }
                    }
                }
            }
        }
        return $array;
    }


    /**
     * Test if valid classname has been set
     * @param null
     * @return Boolean
     */
    public function hasValidDataObject()
    {
        return ( ! $this->DataObject) || ClassInfo::exists($this->DataObject) ? true : false;
    }

    /**
     * Test if valid fields have been set
     * @param null
     * @return Boolean
     */
    public function hasValidDataObjectFields()
    {
        if (!$this->hasValidDataObject()) {
            return false;
        }
        $emailFieldOptions = $this->emailFieldOptions();
        if(!isset($emailFieldOptions[$this->EmailField])) {
            return false;
        }
        $dateFieldOptions = $this->dateFieldOptions();
        if(!isset($dateFieldOptions[$this->DateField])) {
            return false;
        }
        return true;
    }

    public function getTitle()
    {
        return (
            $this->hasValidDataObjectFields()) ?
            '[' . $this->EmailSubject . '] - send ' . $this->Days . ' days '.$this->BeforeAfter.' Expiration Date'
            :
            'uncompleted';
    }

    function hasValidFields(){
        if (!$this->hasValidDataObject()) {
            return false;
        }        
        if (!$this->hasValidDataObjectFields()) {
            return false;
        }
        if($this->EmailFrom && $this->EmailSubject && $this->Content) {
            return true;
        }
        return false;
    }


    public function validate()
    {
        $valid = parent::validate();
        if( $this->exists()) {
            if ( ! $this->hasValidDataObject()) {
                $valid->error('Please enter valid Tabe/Class name ("' . htmlspecialchars($this->DataObject) .'" does not exist)');
            } elseif (! $this->hasValidDataObjectFields()) {
                $valid->error('Please select valid fields for both Email & Date');
            } elseif ( ! $this->hasValidFields()) {
                $valid->error('Please fill in all fields');
            }
        }
        return $valid;
    }

    function onBeforeWrite()
    {
        parent::onBeforeWrite();
        if($this->RepeatDays < ($this->Days * 3)) {
            $this->RepeatDays = ($this->Days * 3);
        }
    }

    function onAfterWrite()
    {
        parent::onAfterWrite();
        if($this->SendTestTo) {
            if($mailOutObject = $this->getMailOutObject()) {
                $mailOutObject->setTestOnly(true);
                $mailOutObject->setVerbose(true);
                $mailOutObject->run(null);
            }
        }
    }

    /**
     *
     * @return null | EmailReminder_ReplacerClassInterface
     */ 
    function getReplacerObject()
    {
        if($mailOutObject = $this->getMailOutObject()) {
            return $mailOutObject->getReplacerObject();
        } 
    }

    /**
     *
     * @return null | ScheduledTask
     */ 
    function getMailOutObject()
    {
        $mailOutClass = $this->Config()->get('mail_out_class');
        if(class_exists($mailOutClass)) {
            $obj = Injector::inst()->get($mailOutClass);
            if($obj instanceof ScheduledTask){
                return $obj;
            } else {
                user_error($mailOutClass.' needs to be an instance of a Scheduled Task');
            }
        }
    }


}
