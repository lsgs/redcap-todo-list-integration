<?php
/**
 * REDCap External Module: To-Do List Integration
 * Enable in project to facilitate creation of requests in the Control Center To-Do List from project records
 * @author Luke Stevens, Murdoch Children's Research Institute
 */
namespace MCRI\ToDoListIntegration;

use ExternalModules\AbstractExternalModule;

class ToDoListIntegration extends AbstractExternalModule
{
    private $project_id;
    private $record;
    private $instrument;
    private $event_id;
    private $repeat_instance;
    private $settings;
    private $source_field_request_id;

    public function redcap_every_page_top($project_id) {
        if (!defined('PAGE') || !defined('USERID')) return;

        if (!is_null($project_id)) return;

        $user = $this->getUser();
        if (!isset($user) || !$user->isSuperUser()) return;

        // superuser on non-project page - get count of pending To-Do List items and add as navbar badge
        $todoListItemsPending = \ToDoList::getTotalNumberRequestsByStatus('pending') + \ToDoList::getTotalNumberRequestsByStatus('low-priority');
        if ($todoListItemsPending > 0) {
            $todoListItemsPendingBadge = ' <a title="'.\RCView::tt_attr('control_center_446').'" href="'.APP_PATH_WEBROOT.'ToDoList/index.php"><span class="badgerc">'.$todoListItemsPending.'</span></a>';
            ?>
            <script type="text/javascript">
                $(function(){
                    $('a.nav-link[href*=ControlCenter]:first').append('<?=$todoListItemsPendingBadge?>');
                });
            </script>
            <?php
        }
        
        if (PAGE==='ToDoList/index.php') {
            // on To-Do list page...
            $system_settings = $this->getSystemSettings();
            $typeBgCol = array();
            if (isset($system_settings['todo-list-type']['value']) && is_array($system_settings['todo-list-type']['value'])) {
                foreach ($system_settings['todo-list-type']['value'] as $idx => $value) {
                    if (isset($system_settings['todo-list-type-rgb']['value'][$idx])) {
                        $thisTypeBgCol = new \stdClass();
                        $thisTypeBgCol->type = $this->escape($system_settings['todo-list-type']['value'][$idx]);
                        $thisTypeBgCol->color = $this->escape($system_settings['todo-list-type-rgb']['value'][$idx]);
                        $typeBgCol[] = $thisTypeBgCol;
                    }
                }
            }
            ?>
            <script type="text/javascript">
                $(function(){
                    // External Module ToDoListIntegration: remove clickability of request comment (use buttons instead) and show whole comment (making URLs into hyperlinks)
                    let replacePattern = /(\b(https?|ftp):\/\/[-A-Z0-9+&@#\/%?=~_|!:,.;]*[-A-Z0-9+&@#\/%=~_|])/gim;
                    $('p.todo-comment').each(function() {
                        let thisComment = $(this).attr('data-comment').replace(replacePattern, '<a href="$1" target="_blank">$1</a>');
                        $(this).html(thisComment);
                    });
                    setTimeout(function(){
                        $('p.todo-comment').off('click');
                    },1000);
                    // override default type/colour settings where set via system settings
                    let mapTypeCol = <?=\json_encode_rc($typeBgCol)?>;
                    try {
                        mapTypeCol.forEach(function(e){
                            console.log(e);
                            let type = e.type;
                            let color = e.color;
                            $('p:contains("'+e.type+'")').parent('div.request-container').css('background-color', e.color);
                        });
                    } catch (error) {
                        console.log(error);
                    }
                });
            </script>
            <style type="text/css">
                .more-info-container .todo-comment:hover {
                    text-decoration: inherit;
                    cursor: auto;
                }
            </style>
            <?php
        }
    }

    function redcap_save_record($project_id, $record, $instrument, $event_id, $group_id=null, $survey_hash=null, $response_id=null, $repeat_instance=1) {
        global $Proj;
        $this->project_id = $project_id;
        $this->record = $record;
        $this->instrument = $instrument;
        $this->event_id = $event_id;
        $this->repeat_instance = $repeat_instance;
        
        $this->settings = $this->getProjectSettings();
        
        if (array_search($this->instrument, $this->settings['trigger-form'])===false) return;
        if (!empty($this->settings['trigger-logic']) && true!==\REDCap::evaluateLogic($this->settings['trigger-logic'], $this->project_id, $this->record, $this->event_id, $this->repeat_instance)) return;
        $this->source_field_request_id = (isset($this->settings['request-id'])) ? $this->escape($this->settings['request-id']) : null;

        if (empty($this->source_field_request_id)) return; // field for todo list request id is required

        try {
            if (!array_key_exists($this->source_field_request_id, $Proj->metadata)) {
                throw new \Exception("Configuration error: invalid request id field '$this->source_field_request_id'");
            }

            $request = $this->readDataForRequest();
            if (empty($request[0])) {
                throw new \Exception("No username/id specified for to-do list request");
            }


            $request_id = $this->createToDoListRequest($request);
            if (empty($request_id)) {
                throw new \Exception("Could not create to-do list request");
            }

            $this->updateSourceRecord($request_id);
            
        } catch (\Throwable $th) {
            $this->notify_exception($th);
        }
    }

    /**
     * readDataForRequest()
     * Gather and prepare the data for the new to-do list record
     * @return Array(8) $request_data
     */
    protected function readDataForRequest(): Array {
        global $Proj;
        $request_data = array();
        $config_fields = array('from'=>null,'to'=>null,'type'=>null,'action-url'=>null,'project-id'=>null,'comment'=>null,'type-id'=>null); // Nb. sequence of values must match sequence of args passed to \ToDoList::insertAction()
        $read_fields = array($this->Proj->table_pk, $this->source_field_request_id);

        foreach (array_keys($config_fields) as $cf) {
            $config_fields[$cf] = $this->settings['request-'.$cf];
            if (!is_null($config_fields[$cf])) $read_fields[] = $config_fields[$cf];
        }

        $record_data = \REDCap::getData(array(
            'return_format' => 'array', 
            'records' => [$this->record],
            'events' => [$this->event_id],
            'fields' => $read_fields
        ));

        for ($d=0; $d<count($config_fields); $d++) {
            $cfKey = array_keys($config_fields)[$d];
            $cf = $config_fields[$cfKey];
            $fieldVal = (empty($cf)) ? null : $this->getFieldValue($cf, $record_data);
            switch ($cfKey) {
                case 'from':
                    $request_data[$d] = $this->checkUserFrom($fieldVal);
                    break;
                case 'project-id':
                    $request_data[$d] = $this->checkPid($fieldVal);
                    break;
                case 'action-url':
                    $request_data[$d] = $this->getActionUrl($fieldVal);
                    break;
                case 'to':
                case 'type':
                case 'type-id':
                case 'comment':
                    $request_data[$d] = (empty($fieldVal)) ? null : \REDCap::filterHtml($fieldVal);
                    break;
                default:
                    break;
            }
        }
        return $request_data;
    }

    /**
     * getFieldValue()
     * Get the falue for the specified field from the given data array, or null if not found or ""
     * Note, can only be within the same record/event/instance as the trigger
     * @param string $field_name
     * @param Array $record_data
     * @return ?string $value
     */
    protected function getFieldValue($field_name, $record_data): ?string {
        global $Proj;
        $value = null;
        $instrument = $Proj->metadata[$field_name]['form_name'];

        if ($Proj->isRepeatingEvent($this->event_id)) {
            $value = $record_data[$this->record]['repeat_instances'][$this->event_id][$instrument][$this->repeat_instance][$field_name];
        } else if ($Proj->isRepeatingForm($this->event_id, $instrument)) {
            $value = $record_data[$this->record]['repeat_instances'][$this->event_id][$instrument][$this->repeat_instance][$field_name];
        } else {
            $value = $record_data[$this->record][$this->event_id][$field_name];
        }
        return ($value==='') ? null : $value;
    }

    /**
     * checkUserFrom()
     * Check usernme or ui_id is a valid redcap user and return the ui_id, or null if not
     * @param ?string $user
     * @return ?int $ui_id
     */
    protected function checkUserFrom(?string $user): ?int {
        if (empty($user)) return null;
        $ui_id = null;
        if (is_numeric($user)) {
            // look up number as ui_id
            $user = intval($user);
            $sql = "SELECT ui_id FROM redcap_user_information WHERE ui_id = ? LIMIT 1";
        } else {
            // look up string as username 
            $user = trim($user);
            $sql = "SELECT ui_id FROM redcap_user_information WHERE username = ? LIMIT 1";
        }
        $ui_id = intval($this->readFirstValue($sql, [$user]));
        return (empty($ui_id)) ? null : $ui_id;
    }

    /**
     * checkPid()
     * Check project id is a valid project and return the pid, or null if not
     * @param ?string $project_id
     * @return ?int $pid
     */
    protected function checkPid(?string $project_id): ?int {
        if (empty(intval($project_id))) return null;
        $sql = "SELECT project_id FROM redcap_projects WHERE project_id = ? LIMIT 1";
        $pid = intval($this->readFirstValue($sql, [intval($project_id)]));
        return (empty($pid)) ? null : $pid;
    }

    /**
     * getActionUrl()
     * If an action URL value is specified then sanitise and return it 
     * If no action URL specified then return the request completion URL provide by this module
     * @param ?string url in
     * @return ?string url out
     */
    protected function getActionUrl(?string $url): ?string {
        if (!empty($url)) return \REDCap::filterHtml($url);
        return $this->getUrl('plugin.php',false,false);
    }

    /**
     * readFirstValue()
     * Get the value from the first column and row of the supplied SQL query given the supplied parameter set, or null if empty/no results
     * @param ?string $sql
     * @param Array $params
     * @return mixed $result
     */
    protected function readFirstValue(string $sql, Array $params): mixed {
        $q = $this->query($sql, $params);
        $row = $q->fetch_array(MYSQLI_NUM);
        return (is_array($row)) ? $row[0] : null;
    }

    /**
     * createToDoListRequest()
     * Create a new record in redcap_todo_list for this request and return the new pk value
     * @param Array $request_data
     * @return int $request_id
     */
    protected function createToDoListRequest(Array $request_data): int {
        if (count($request_data) !== 7) { throw new \Exception("Unexpected data value count: expected=7; provided=".count($request_data)." \n ".print_r($this->escape($request_data))); }
        //$request_data = $this->escape($request_data);
        //$request_data[] = NOW;
        //$sql = "INSERT INTO redcap_todo_list (request_from,request_to,todo_type,action_url,project_id,comment,todo_type_id,request_time) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        //$this->query($sql, $request_data);
        //$pk = db_insert_id();
        $pk = \ToDoList::insertAction(...$request_data);
        if (empty($pk)) { throw new \Exception("Could not create to-do list request"); }
        return $pk;
    }

    /**
     * updateSourceRecord()
     * Record the created to--do list request id in the designated field in the source project
     * @param int $request_id
     * @return void
     */
    protected function updateSourceRecord(int $request_id): void {
        global $Proj;
        $saveData = array(
            $Proj->table_pk => $this->record,
            $this->source_field_request_id => $request_id
        );
        if (\REDCap::isLongitudinal()) {
            $saveData['redcap_event_name'] = \REDCap::getEventNames(true, false, $this->event_id);
        }
        if (!empty($this->repeat_instance) && $this->repeat_instance > 1) {
            $saveData['redcap_repeat_instrument'] = ($Proj->isRepeatingEvent($this->event_id)) ? '' : $this->instrument;
            $saveData['redcap_repeat_instance'] = $this->repeat_instance;
        }

        $saveResult = \REDCap::saveData('json', json_encode(array($saveData)), 'overwrite');

        if (isset($saveResult['errors']) && !empty($saveResult['errors'])) {
            $detail = "Error saving request id to field: $this->source_field_request_id; Value: $request_id";
            $detail .= " \nErrors: ".print_r($saveResult['errors'], true);
            throw new \Exception($detail);
        }
    }

    /**
     * notify()
     * Send email notification to configured recipients
     * @param string $subject 
     * @param string $bodyDetail 
     * @return void
     */
    protected function notify(string $subject,string $bodyDetail): void {
        global $project_contact_email;
        $bodyDetail = str_replace(PHP_EOL,'<br>',$bodyDetail);
        $failEmails = $this->getProjectSetting('fail-alert-email');
        if (is_array($failEmails) && count($failEmails)>0 && !empty($failEmails[0])) {
            $email = new \Message();
            $email->setFrom($project_contact_email);
            $email->setTo(implode(';', $failEmails));
            $email->setSubject($subject);
            $email->setBody("$subject<br><br>$bodyDetail", true);
            $email->send();
        }
    }

    /**
     * notify_exception()
     * @param \Throwable $th
     * @return void
     */
    protected function notify_exception(\Throwable $th): void {
        $url = APP_PATH_WEBROOT_FULL."DataEntry/index.php?pid=$this->project_id&id=$this->record&page=$this->instrument&event_id=$this->event_id&instance=$this->repeat_instance";
        $title = "To-Do List Integration External Module Exception Notification (pid=$this->project_id; record=$this->record)";
        $detail = "<h4>$title</h4>";
        $detail .= $th->getMessage();
        $detail .= "<p>";
        $detail .= "pid=$this->project_id <br>";
        $detail .= "record=$this->record <br>";
        $detail .= "instrument=$this->instrument <br>";
        $detail .= "event_id=$this->event_id <br>";
        $detail .= "instance=$this->repeat_instance <br>";
        $detail .= "</p><p><a href='$url'>$url</a></p>".

        $this->notify($title, $detail);
    }

    protected function requestIsOpen(int $request_id): bool {
        $sql = "select request_id from redcap_todo_list where status!='completed' and request_id=? ";
        return (bool)$this->readFirstValue($sql, [$request_id]);
    }

    public function page_confirm_complete(int $request_id): void {
        // verify request id is valid, open request
        if (!$this->requestIsOpen($request_id)) {
            echo $this->errorMessage("request $request_id not open");
            return;
        } 

        $this->initializeJavascriptModuleObject();
        ?>
        <script type="text/javascript">
            let module = <?=$this->getJavascriptModuleObjectName()?>;
            module.request_id = <?=$request_id?>;
            module.title = 'Complete Request';
            module.content = 'Confirm you wish to mark request #'+module.request_id+' "Completed"?';
            module.btnConfirmHandler = function(){
                module.ajax('complete-request', module.request_id).then(function(response) {
                    if (response) {
                        simpleDialog('Request #'+module.request_id+' complete');
                    } else {
                        alert(woops);
                    }
                });
            };
            module.btnCancelHandler = function(){};
            module.init = function() {
                // simpleDialog(content,title,id,width,onCloseJs,closeBtnTxt,okBtnJs,okBtnTxt,autoOpen)
                simpleDialog(module.content,module.title,'ToDoListRequestComplete',400,module.btnCancelHandler,'Cancel',module.btnConfirmHandler,'Confirm',true)
            };
            $(document).ready(function(){
                module.init();
            });
        </script>
        <?php
    }

    /**
     * redcap_module_page_ajax()
     * Record to-do list request completed
     */
    public function redcap_module_ajax($action, $payload, $project_id, $record, $instrument, $event_id, $repeat_instance, $survey_hash, $response_id, $survey_queue_hash, $page, $page_full, $user_id, $group_id) {
        if ($action != 'complete-request') return 0;
        $user = $this->getUser();
        if (!isset($user) || !$user->isSuperUser()) return 0;

        $request_id = intval($payload);
        if (!$this->requestIsOpen($request_id)) return 0;
        $pid = $this->readFirstValue("select project_id from redcap_todo_list where request_id=? ", [$request_id]);
        $type = $this->readFirstValue("select todo_type from redcap_todo_list where request_id=? ", [$request_id]);
        $result = \ToDoList::updateTodoStatus($pid,$type,'completed',null,$request_id);
        return ($result) ? 1 : 0;
    }

    public function errorMessage($msg): string {
        $msg = \RCView::tt('global_01').((empty($msg)) ? '' : \RCView::tt('colon').' '.$msg);
        return "<div class='red'>$msg</div>";
    }
}