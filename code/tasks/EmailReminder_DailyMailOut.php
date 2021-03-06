<?php


class EmailReminder_DailyMailOut extends DailyTask
{

    /**
     * @var int
     */ 
    private static $days_before_same_notification_can_be_sent_to_same_user = 100;

    /**
     * @var int
     */ 
    private static $limit = 20;

    /**
     * @var int
     */ 
    private static $grace_days = 3;

    /**
     * @var string
     */ 
    private static $replacer_class = 'EmailReminder_ReplacerClassBase';


    protected $verbose = false;
    
    protected $testOnly = false;

    /**
     * The object that replaces tags in the subject and content.
     * @var EmailReinder_ReplacerClassInterface
     */ 
    protected $replacerObject = null;


    function setVerbose($b)
    {
        $this->verbose = $b;
    }

    function setTestOnly($b)
    {
        $this->testOnly = $b;
    }

    /**
     *
     * @todo: https://docs.silverstripe.org/en/3.1/developer_guides/extending/injector/ implement
     * for email class to be used...
     *
     * expire date = 08-09
     * days before 7
     * min: current date + 7 - grace days
     * min: current date + 7
     *
     * expire date = 08-09
     * days after 7
     * min: current date - 7
     * max current date - 7 - grace days
     * 
     * @param  [type] $request [description]
     * @return [type]          [description]
     */
    public function run($request)
    {
        //CRUCIAL !
        //
        Email::set_mailer(new EmailReminder_Mailer());    

        $reminders = EmailReminder_NotificationSchedule::get();

        foreach ($reminders as $reminder) {
            if ( ! $reminder->hasValidFields()) {
                continue; // skip if task is not valid
            }
            if ( $reminder->Disabled) {
                continue; // skip if task is disable
            }

            $do = $reminder->DataObject;
            $sign = $reminder->BeforeAfter == 'before' ? '+' : '-';
            $graceDays = Config::inst()->get('EmailReminder_DailyMailOut', 'grace_days');
            
            if($sign == '+') {
                $minDays = $sign . ($reminder->Days - $graceDays) . ' days';
                $maxDays = $sign . $reminder->Days . ' days';
            } else {
                $minDays = $sign . ($reminder->Days - $graceDays) . ' days';
                $maxDays = $sign . $reminder->Days . ' days';
            }
            
            $minDate = date('Y-m-d', strtotime($minDays)).' 00:00:00';
            $maxDate = date('Y-m-d', strtotime($maxDays)).' 23:59:59';

            // Use StartsWith to match Date and DateTime fields
            if($this->testOnly) {
                if($reminder->SendTestTo) {
                    $emails = explode(',', $reminder->SendTestTo);
                    foreach($emails as $key => $email) {
                        $this->sendEmail($reminder, $email, $isTestOnly = true);
                    }
                }
            } else {
                $limit = Config::inst()->get('EmailReminder_DailyMailOut', 'daily_limit');
                $records = $do::get()->filter(
                    array(
                        $reminder->DateField . ':GreaterThan' => $minDate,
                        $reminder->DateField . ':LessThan' => $maxDate
                    )
                )->limit($limit);

                if ($records) {
                    foreach ($records as $record) {
                        $this->sendEmail($reminder, $record, $isTestOnly = false);
                    }
                }

            }

        }
    }

    protected function sendEmail($reminder, $recordOrEmail, $isTestOnly)
    {
        if(is_object($recordOrEmail)) {
            $email_field = $reminder->EmailField;
            $email = $recordOrEmail->$email_field;
            $record = $recordOrEmail;

        } else {
            $email = strtolower(trim($recordOrEmail));
            $record = Injector::inst()->get($reminder->DataObject);
        }
        if (Email::validEmailAddress($email)) {

            $send = true;
            $filter = array(
                'EmailTo' => $email,
                'EmailReminder_NotificationScheduleID' => $reminder->ID
            );
            $logs = EmailReminder_EmailRecord::get()->filter($filter);
            $send = true;
            foreach($logs as $log) {
                if( ! $log->canSendAgain()) {
                    $send = false;
                    break;
                }
            }
            if($send) {
                $log = EmailReminder_EmailRecord::create($filter);

                $subject = $reminder->EmailSubject;
                $email_content = $reminder->Content;
                if($replacerObject = $this->getReplacerObject()) {
                    $email_content = $replacerObject->replace($reminder, $record, $email_content);
                    $subject = $replacerObject->replace($reminder, $record, $subject);
                }
                $email_content = $this->getParsedContent($record, $email_content);
                
                /* Parse HTML like a template, and translate any internal links */
                $data = ArrayData::create(array(
                    'Content' => $email_content
                ));

                // $email_body = $record->renderWith(SSViewer::fromString($reminder->Content));
                // echo $record->renderWith('Email_Reminder_Standard_Template');//$email_body;                
                $email = new Email(
                    $reminder->EmailFrom,
                    $email,
                    $subject
                );

                $email->setTemplate('Email_Reminder_Standard_Template');

                $email->populateTemplate($data);

                // $email->send();
                $log->IsTestOnly = $isTestOnly;
                $log->Result = $email->send();
                $log->EmailReminder_NotificationScheduleID = $reminder->ID;
                $log->write();
            }

        }
        return false;
    }

    
    /**
     * @return EmailReminder_ReplacerClassInterface | null
     */ 
    public function getReplacerObject()
    {
        if( ! $this->replacerObject) {
            $replacerClass = Config::inst()->get("EmailReminder_Mailer", "replacer_class");
            if($replacerClass && class_exists($replacerClass)) {
                $interfaces = class_implements($replacerClass);
                if($interfaces && in_array('EmailReminder_ReplacerClassInterface', $interfaces)) {
                    $this->replacerObject = Injector::inst()->get($replacerClass);
                }
            }
        }
        return $this->replacerObject;
    }


    /**
     *
     * @return string
     */  
    public function getParsedContent($record, $content){
        return ShortcodeParser::get_active()
            ->parse(
                $record->renderWith(
                    SSViewer::fromString($content)
                )
            );
    }
    
}
