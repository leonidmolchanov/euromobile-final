<?php
require_once ($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');
// Проверяем сессию
if($_REQUEST['sessid']==bitrix_sessid()):
// Подключаем модули
    CModule::IncludeModule('iblock');
    CModule::IncludeModule("tasks");
    CModule::IncludeModule("department");
    CModule::IncludeModule('calendar');
    CModule::IncludeModule('group');
    CModule::IncludeModule('CTaskPlannerMaintance');
    CModule::IncludeModule("socialnetwork");
    // Определяем глобальную переменную пользователя
    global $USER;
    //Функция парсинга входных параметров
    function getParam($param){
        $arr= explode("/",$param);
        $arResult=[];
        foreach ($arr as $key => $Item):
            if($Item=="user" || $Item=="group"):
                $arResult['userId']=$arr[$key+1];
            elseif($Item=="view"):
                $arResult['taskId']=$arr[$key+1];
            endif;
        endforeach;
        return $arResult;
    }
    // Клас для задания коренных статичных параметров определяющих свойства руководителя по которым производится выборка и последующим порождением модифицированных класов на базе запросов
    class ClassCore {
        public $user;
        function __construct($USER, $href) {
            //Функция запроса пользователей учавствующих в поиске
            function getUserFilterList($UsertDeportament)
            {
                //$filter['UF_TASK_STATUS'] = "1";
                if($UsertDeportament!==40){
                    $filter['UF_DEPARTMENT'] = 40;

                }else {
                    $filter['UF_DEPARTMENT'] = $UsertDeportament;
                }
                $userArray = \Bitrix\Main\UserTable::getList(Array(
                    'select' => Array('ID', 'NAME', 'SECOND_NAME', 'LAST_NAME', 'UF_DEPARTMENT', 'UF_TASK_WORK_TIME'),
                    'filter' => $filter
                ))->fetchAll();
                return $userArray;
            }
            function getUserFilterListForce($UsertID)
            {
                //$filter['UF_TASK_STATUS'] = "1";
                $filter['ID'] = $UsertID;
                $userArray = \Bitrix\Main\UserTable::getList(Array(
                    'select' => Array('ID', 'NAME', 'SECOND_NAME', 'LAST_NAME', 'UF_DEPARTMENT', 'UF_TASK_WORK_TIME'),
                    'filter' => $filter
                ))->fetchAll();
                return $userArray;
            }
            function getSubtask($task){
                $subtask = CTasks::GetList(Array("TITLE" => "ASC"), Array("PARENT_ID" => $task['ID']));
                $arSubtask=[];
                while ($arTask = $subtask->GetNext())
                {
                    array_push($arSubtask, $arTask );
                }
                return $arSubtask;
            }
            function getGroup(){
                $group = CSocNetGroup::GetList(Array(), Array());
                $arGroup=[];
                while ($arr = $group->GetNext())
                {
                    array_push($arGroup, $arr );
                }
                return $arGroup;
            }
            function createCalendar($holidey, $weekand){
                $content=[];
                $content[0]=[];
                $content[1]=[];
                for ($i = 0; $i <= 30; $i++):
                    if(in_array((date("j.m", strtotime("+" . $i . " day"))),$holidey)):
                    $state = true ;
                elseif(in_array((mb_strtoupper(substr(date("D", strtotime("+" . $i . " day")), 0, -1))),$weekand)):
                    $state = true ;
                    else:
                        $state = false ;
                        endif;
                    array_push( $content[0], [date("d.m.Y", strtotime("+" . $i . " day")), $state]);
                    array_push( $content[1], [date("j", strtotime("+" . $i . " day")), $state]);
                endfor;
                return $content;
            }

            if($href!==false) {
                $task= new CTaskItem($href['taskId'], $USER->GetId());
                $this->task = $task->getData();
                $this->subtask = getSubtask($task->getData());
                $this->group = getGroup();
            }

//            $this->user->departament = $USER->GetById($USER->GetId())->Fetch()['UF_DEPARTMENT'];
            $this->user->access = $USER->GetById($USER->GetId())->Fetch()['UF_TASK_ACCESS'];
            $this->user->ID = $USER->GetId();
            if($href==false && $this->user->access==0) {
                $this->user->group = getUserFilterListForce($this->user->ID);

            }
            else{
                $this->user->group = getUserFilterList($this->user->departament);
            }
            $this->worktime->holidey = explode(",", CCalendar::GetSettings()['year_holidays']);
            $this->worktime->weekand = CCalendar::GetSettings()['week_holidays'];
            $this->worktime->starttime = CCalendar::GetSettings()['work_time_start'];
            $this->worktime->endtime = CCalendar::GetSettings()['work_time_end'];
            $this->calendar = createCalendar($this->worktime->holidey, $this->worktime->weekand)[0];
            $this->calendarModify = createCalendar($this->worktime->holidey, $this->worktime->weekand)[1];
            foreach ($this->user->group as $key => $Item):
                $tasks = CTasks::GetList(Array("TITLE" => "ASC"), Array("RESPONSIBLE_ID" => $this->user->group[$key]['ID']));
                $tasksArr=[];
                while ($arTask = $tasks->GetNext())
                {
                    $end=strtotime($arTask['END_DATE_PLAN']);
                    $start=strtotime($arTask['START_DATE_PLAN']);
                    $procent;
                    if(strtotime(date("d.m.Y H:i"))<strtotime($arTask['START_DATE_PLAN'])):
                        $procent=0;
                    elseif(strtotime(date("d.m.Y H:i"))>strtotime($arTask['END_DATE_PLAN'])):
                        $procent=100;
                    elseif(strtotime(date("d.m.Y H:i"))>strtotime($arTask['START_DATE_PLAN']) && strtotime(date("d.m.Y H:i"))<strtotime($arTask['END_DATE_PLAN'])):
                        $time = $end - $start;
                        $cof = strtotime(date("d.m.Y H:i"))-strtotime($arTask['START_DATE_PLAN']);
                        $procent=($cof/$time)*100;
                        endif;
                    $arTask['COMPLETE_STATUS'] = $procent;
                    array_push($tasksArr, $arTask);
                }
                $User = CUser::GetByID($this->user->group[$key]['ID']);
                $arUser = $User->Fetch();
                $this->user->group[$key]['ACCESSIBILITY'] =CCalendar::GetAccessibilityForUsers(array("users" => array($this->user->group[$key]['ID'])));
                $this->user->group[$key]['TASKS'] = $tasksArr;
                $this->user->group[$key]['PROPERTIES'] = $arUser;
            endforeach;
            if($href == false){
                $this->group = getGroup();
            }
        }
    }



    // Модуль создания задачи
    if($_REQUEST['type']=="addTasks"):

        // Функция проверки свободного времении на подзадачи
        function checkfreeTime($subtaskArr){
            $result=false;
            foreach ($subtaskArr as $key => $item):
                $date=date("d.m.Y",strtotime($item[0]));
                $timeStart =strtotime($date.' '.$item[1]);
                $timeEnd=strtotime("+".$item[2]." hours",strtotime($date.' '.$item[1]));
                $subtask = CTasks::GetList(Array("TITLE" => "ASC"), Array("RESPONSIBLE_ID" => $_REQUEST['userid'],
                    "STATUS" => 2,
                    '>=START_DATE_PLAN' => $date.' 00:00',
                    '<=END_DATE_PLAN' => $date.' 23:99'));
                while ($arTask = $subtask->GetNext())
                {
                    if($timeStart > strtotime($arTask['START_DATE_PLAN']) && $timeStart < strtotime($arTask['END_DATE_PLAN'])):
                        $result=true;
                    endif;
                    if($timeEnd > strtotime($arTask['START_DATE_PLAN']) && $timeEnd < strtotime($arTask['END_DATE_PLAN'])):
                        $result=true;
                    endif;
                    if($timeStart <= strtotime($arTask['START_DATE_PLAN']) && $timeEnd >= strtotime($arTask['END_DATE_PLAN'])):
                        $result=true;
                    endif;
                }
            endforeach;
            return $result;
        }

        if(!checkfreeTime($_REQUEST['data'])) {

      $core = new ClassCore($USER, getParam($_REQUEST['href']));
    $responce = "error";
    function checkTime($data){
        $responce="success";
        foreach ($data as $key => $item):
            if($item[1]==""):
                $responce = "error";
                break;
            else:
            endif;
            endforeach;
            return $responce;
    }
    if(checkTime($_REQUEST['data'])=="success"):
        $DescriptionText= GetMessage('create_parts_tasks')." ".$core->task['ID']." :";
       //Проверка дедлайна
        foreach ($_REQUEST['data'] as $key => $item):
            if(strtotime($item[0]." ".$item[1])>strtotime($core->task['DEADLINE'])):
                $core->task['DEADLINE'] = date("d.m.Y H:i",strtotime("+".$item[2]." hours",strtotime($item[0]." ".$item[1])));
// Изменяем дедлайн у головной задачи
                if (CModule::IncludeModule("tasks")):
                    $arFields = Array(
                        "DEADLINE" =>  $core->task['DEADLINE'],
                    );
                    $ID = $core->task['ID'];
                    $obTask = new CTasks;
                    $rsTask = CTasks::GetByID($ID);
                    $success = $obTask->Update($ID, $arFields);
                    if ($success) {
                        $responce="success";
                        $DescriptionText="Дата крайнего срока была изменена на : ".$core->task['DEADLINE']."<br>";
                        CTaskComments::Add($core->task['ID'],  1, $DescriptionText);
                    } else {
                        if ($e = $APPLICATION->GetException())
                            $responce="error";
                    }
                endif;



        endif;
        endforeach;

        // Изменение времени на задачу
if($_REQUEST['tasktime']*3600!==$core->task['TIME_ESTIMATE']):
        if (CModule::IncludeModule("tasks")):
            $arFields = Array(
                "TIME_ESTIMATE"=>$_REQUEST['tasktime']*3600,
            );
            $ID = $core->task['ID'];
            $obTask = new CTasks;
            $rsTask = CTasks::GetByID($ID);
            $success = $obTask->Update($ID, $arFields);
            if ($success) {
                $responce="success";
                $DescriptionText="Время на задачу было изменено : ".$_REQUEST['tasktime']."<br>";
                CTaskComments::Add($core->task['ID'],  1, $DescriptionText);
            } else {
                if ($e = $APPLICATION->GetException())
                    $responce="error";
            }
        endif;
endif;
$taskcount=1;
        foreach ($_REQUEST['data'] as $key => $item):
          // echo date("d-m-Y",strtotime($item[0]));
            if (CModule::IncludeModule("tasks"))
            {
                $numberPart = $key+1;
                $arFields = Array(
                    "PARENT_ID"=>$core->task['ID'],
                    "RESPONSIBLE_ID"=>$_REQUEST['userid'],
                    "ALLOW_CHANGE_DEADLINE" => 'N',
                    "DEADLINE"=>date("d.m.Y H:i",strtotime($item[0]." 23:59")),
                    "CREATED_BY"=>$USER->GetId(),
                    "AUDITORS"=> [$USER->GetId()],
                    "TITLE" => "часть №".$numberPart." (".$core->task['ID']." часть №".$taskcount."/".count($_REQUEST['data']).")",
                    "GROUP_ID" => $core->task['GROUP_ID'],
                    "DURATION_PLAN"=>$item[2],
                    "DURATION_TYPE"=>"hours",
                    "TIME_ESTIMATE"=>$item[2]*3600,
                    "ALLOW_TIME_TRACKING"=>"Y",
                    "START_DATE_PLAN"=>date("d.m.Y H:i",strtotime($item[0]." ".$item[1])),
                    "END_DATE_PLAN"=>date("d.m.Y H:i",strtotime("+".$item[2]." hours",strtotime($item[0]." ".$item[1])))
                );

                $obTask = new CTasks;
                $ID = $obTask->Add($arFields);
                $success = ($ID>0);

                if($success)
                {
                    CUserOptions::SetOption('tasks', CTaskPlannerMaintance::PLANNER_OPTION_CURRENT_TASKS, array($ID));
                    $DescriptionText=$DescriptionText."<br>".GetMessage('task_was_create')." ".$ID." (".$taskcount."/".count($_REQUEST['data']).")<br>".GetMessage('time_start')."".date("d.m.Y H:i",strtotime($item[0]." ".$item[1])).
                    "<br> время окончания :".date("d.m.Y H:i",strtotime("+".$item[2]." hours",strtotime($item[0]." ".$item[1])))."<br>".GetMessage('time_for_task')." ".
                        $item[2]." ч. ,<br><br>";
                }
                else
                {
                    if($e = $APPLICATION->GetException())
                        $responce = "error";

                    break;
                }


                $taskcount++;
            }
        endforeach;
        CTaskComments::Add($core->task['ID'],  1, $DescriptionText);
        $arFields = Array(
            "RESPONSIBLE_ID"=>$_REQUEST['userid'],
            "AUDITORS"=> [$USER->GetId()]
        );
        if (CModule::IncludeModule("tasks")):
            $ID = $core->task['ID'];
            $obTask = new CTasks;
            $success = $obTask->Update($ID, $arFields);
            if ($success) {
                $responce="success";

            } else {
                if ($e = $APPLICATION->GetException())
                    $responce="error";
            }
        endif;
        else:
        $responce="error";
            endif;

        }
        else{
        $responce='no-time';
        }
       echo $responce;
    // Возвращение контента для аналитики А3
    elseif ($_REQUEST['type']=="getA3Content"):
        $workGroupArr=[];
//        $dbRequests = CSocNetUserToGroup::GetList(
//            array("USER_LAST_NAME" => "ASC", "USER_NAME" => "ASC"),
//            array(
//            ),
//            false,
//            false,
//            array("ID", "USER_ID", "DATE_CREATE", "DATE_UPDATE", "USER_NAME", "USER_LAST_NAME", "USER_PERSONAL_PHOTO")
//        );
$tasksArr=[];
       if (CModule::IncludeModule("tasks"))
{
    $res = CTasks::GetList(
        Array("TITLE" => "ASC")
//        Array("RESPONSIBLE_ID" => "2")
    );

    while ($arTask = $res->GetNext())
    {
        array_push($tasksArr, $arTask );    }
}
        $core = new ClassCore($USER, false );
        $request = Array("CORE"=>$core,
            "TASKS"=>$tasksArr);
        echo json_encode($request);

    elseif ($_REQUEST['type']=="getA5Content"):
        $core = new ClassCore($USER, false );
        $tasksArr=[];
        if (CModule::IncludeModule("tasks"))
        {
            $res = CTasks::GetList(
                Array("TITLE" => "ASC"),
        Array("!PARENT_ID" => null, "STATUS"=>'5')
            );

            while ($arTask = $res->GetNext())
            {
                array_push($tasksArr, $arTask );    }
        }
        $data= Array();
        $calendar = Array(
            '01'=>'Январь',
            '02'=>'Февраль',
            '03'=>'Март',
            '04'=>'Апрель',
            '05'=>'Май',
            '06'=>'Июнь',
            '07'=>'Июль',
            '08'=>'Август',
            '09'=>'Сентябрь',
            '10'=>'Октябрь',
            '11'=>'Ноябрь',
            '12'=>'Декабрь'
        );
        for ($i = 0; $i <= 11; $i++) {

            array_push($data,Array('title'=>date('Y-m',strtotime("-".$i." month"))." (".$calendar[date('m',strtotime("-".$i." month"))].")"));
        }
$userTaskData=[];
        foreach ($core->user->group as $item){
            $userTaskData2=[];

            for ($i = 0; $i <= 11; $i++) {
                $y=$i-1;
                $userTasks=0;
                $userTime=0;
                foreach ($tasksArr as $item2){
                    if (strtotime($item2['CREATED_DATE'])>strtotime(date('Y-m-01',strtotime("-".$i." month"))) && strtotime($item2['CREATED_DATE'])<strtotime(date('Y-m-01',strtotime("-".$y." month")))) {

                        if ($item2['RESPONSIBLE_ID'] == $item['ID']) {
                            $userTasks++;
                            $userTime += $item2['TIME_ESTIMATE'];
                        }
                    }
                }
                array_push($userTaskData2, Array('NAME'=>$item['NAME'],'TASKS'=>$userTasks, 'TIME'=>$userTime));

            }
            array_push($userTaskData,$userTaskData2);
        }

    $request=Array('HEAD'=>$data, 'BODY'=>$userTaskData);
echo json_encode($request);
        // Возвращение задачи
    elseif ($_REQUEST['type']=="returnTask"):
        $core = new ClassCore($USER, false);

        function gettaskF($taskId){
            $subtask = CTasks::GetList(Array("TITLE" => "ASC"), Array("PARENT_ID" => $taskId));
            $arSubtask=[];
            while ($arTask = $subtask->GetNext())
            {
                array_push($arSubtask, $arTask );
            }
            return $arSubtask;
        }
        $DescriptionText="";
        $userId = $USER->getId();
        $oTask = CTaskItem::getInstance($_REQUEST['taskid'], $userId);
        $task= $oTask->getData();
        $arFields = Array(
            "RESPONSIBLE_ID"=>$task["CREATED_BY"],
            //"CREATED_BY"=>$task["AUDITORS"][0],
            "AUDITORS"=> [],
            "TIME_ESTIMATE"=>"0",
            "ALLOW_TIME_TRACKING"=>"N"

        );
        function getProcent($arTask){
            $end=strtotime($arTask['END_DATE_PLAN']);
            $start=strtotime($arTask['START_DATE_PLAN']);
            $procent;
            if(strtotime(date("d.m.Y H:i"))<strtotime($arTask['START_DATE_PLAN'])):
                $procent=0;
            elseif(strtotime(date("d.m.Y H:i"))>strtotime($arTask['END_DATE_PLAN'])):
                $procent=100;
            elseif(strtotime(date("d.m.Y H:i"))>strtotime($arTask['START_DATE_PLAN']) && strtotime(date("d.m.Y H:i"))<strtotime($arTask['END_DATE_PLAN'])):
                $time = $end - $start;
                $cof = strtotime(date("d.m.Y H:i"))-strtotime($arTask['START_DATE_PLAN']);
                $procent=($cof/$time)*100;
            endif;
            return $procent;

        }
                    if (CModule::IncludeModule("tasks")):
            $ID = $_REQUEST['taskid'];
            $obTask = new CTasks;
            $success = $obTask->Update($ID, $arFields);
            if ($success) {
                $DescriptionText = $DescriptionText."<br>".GetMessage('task_number')."".$ID."".GetMessage('was_return');
                foreach (gettaskF($_REQUEST['taskid']) as $key => $Item):
                    if (CModule::IncludeModule("tasks")):
                        $ID = $Item['ID'];
                        $task= new CTaskItem($ID, $USER->GetId());
                        $arTask = $task->getData();
                        $procent = getProcent($arTask);
                        if($procent > 0){
                            $arFields = Array(
                                "PARENT_ID" => null,
                                "STATUS" =>  5
                            );
                            $obTask = new CTasks;
                            $success = $obTask->Update($ID, $arFields);
                            $message = GetMessage('subtask_number')." ". $ID ." ".GetMessage('was_close_and_save');
                            CTaskComments::Add($_REQUEST['taskid'],  1, $message);
                            $message = GetMessage('subtask_number')." ". $ID ." ".GetMessage('was_close_and_save');
                            CTaskComments::Add($ID,  1, $message);
                        }
                        else {
                            CTasks::Delete($ID);
                            $DescriptionText = $DescriptionText ."<br>".GetMessage('subtask_number')." ". $ID ." ".GetMessage('was_delete');
                        }
                    endif;
                endforeach;
                $responce="success";
                CTaskComments::Add($_REQUEST['taskid'],  1, $DescriptionText);
            } else {
                if ($e = $APPLICATION->GetException())
                    $responce="error";
            }
        endif;
echo $responce;
// Закрытие задачи
    elseif ($_REQUEST['type']=="closeTasks"):
            function gettaskF($taskId){
                $subtask = CTasks::GetList(Array("TITLE" => "ASC"), Array("PARENT_ID" => $taskId));
                $arSubtask=[];
                while ($arTask = $subtask->GetNext())
                {
                    array_push($arSubtask, $arTask );
                }
                return $arSubtask;
            }
            $DescriptionText= "";
            $core = new ClassCore($USER, false);
            $arFields = Array(
                "STATUS" =>  5
            );
            $responce="error";
            if (CModule::IncludeModule("tasks")):
            $ID = $_REQUEST['taskid'];
            $obTask = new CTasks;
            $success = $obTask->Update($ID, $arFields);
                if ($success) {
                $responce="success";
                $DescriptionText = $DescriptionText."".GetMessage('task_number')." ".$ID."".GetMessage('was_close')."".$USER->GetFullName();

            } else {
                if ($e = $APPLICATION->GetException())
                    $responce="error";
            }
        endif;
            foreach (gettaskF($_REQUEST['taskid']) as $key => $Item):
                if (CModule::IncludeModule("tasks")):
                    $ID = $Item['ID'];
                    $obTask = new CTasks;
                    $success = $obTask->Update($ID, $arFields);
                    if ($success) {
                        $responce="success";
                        $DescriptionText = $DescriptionText."".GetMessage('task_number')."".$ID.", " ;
                    } else {
                        if ($e = $APPLICATION->GetException())
                            $responce="error";
                    }
                endif;
                endforeach;
            $DescriptionText = $DescriptionText.GetMessage('was_close_too');
            CTaskComments::Add($_REQUEST['taskid'],  1, $DescriptionText);
            echo $responce;
            // Открытие задачи
    elseif ($_REQUEST['type']=="openTasks"):
        $core = new ClassCore($USER, getParam($_REQUEST['href']));
        $arFields = Array(
            "STATUS" =>  2
        );
        $responce="error";
        foreach ($core->subtask as $key => $Item):
            if (CModule::IncludeModule("tasks")):
                $ID = $Item['ID'];
                $obTask = new CTasks;
                $success = $obTask->Update($ID, $arFields);
                if ($success) {
                    $responce="success";
                    $DescriptionText = GetMessage('task_number')." ".$ID."".GetMessage('was_open')."".$USER->GetFullName();
                    CTaskComments::Add($ID,  1, $DescriptionText);

                } else {
                    if ($e = $APPLICATION->GetException())
                        $responce="error";
                }
            endif;
        endforeach;
        echo $responce;
        elseif ($_REQUEST['type']=="setTaskTime"):
            $arFields = Array(
                "TIME_ESTIMATE"=>$_REQUEST['data']['value']*3600,
                "ALLOW_TIME_TRACKING"=>"Y"
            );
            $responce;
            if (CModule::IncludeModule("tasks")):
                $ID = $key;
                $obTask = new CTasks;
                $success = $obTask->Update($_REQUEST['data']['taskid'], $arFields);
                if ($success) {
                    $responce="success";
                    $DescriptionText=GetMessage('task_add_new_time')."".$_REQUEST['data']['value']." ".GetMessage('hour');
                    CTaskComments::Add($_REQUEST['data']['taskid'],  1, $DescriptionText);
                } else {
                    if ($e = $APPLICATION->GetException())
                        $responce="error";
                }
            endif;
                echo $responce;
                // Запрос более детальной информации по подзадачам
                elseif ($_REQUEST['type']=="showDetailTask"):
                    function getSubtask($task){
                        $subtask = CTasks::GetList(Array("TITLE" => "ASC"), Array("PARENT_ID" => $task['ID']));
                        $arSubtask=[];
                        while ($arTask = $subtask->GetNext())
                        {
                            $end=strtotime($arTask['END_DATE_PLAN']);
                            $start=strtotime($arTask['START_DATE_PLAN']);
                            $procent;
                            if(strtotime(date("d.m.Y H:i"))<strtotime($arTask['START_DATE_PLAN'])):
                                $procent=0;
                            elseif(strtotime(date("d.m.Y H:i"))>strtotime($arTask['END_DATE_PLAN'])):
                                $procent=100;
                            elseif(strtotime(date("d.m.Y H:i"))>strtotime($arTask['START_DATE_PLAN']) && strtotime(date("d.m.Y H:i"))<strtotime($arTask['END_DATE_PLAN'])):
                                $time = $end - $start;
                                $cof = strtotime(date("d.m.Y H:i"))-strtotime($arTask['START_DATE_PLAN']);
                                $procent=($cof/$time)*100;
                            endif;
                            $arTask['COMPLETE_STATUS'] = $procent;
                            array_push($arSubtask, $arTask );
                        }
                        return $arSubtask;
                    }
                    $task= new CTaskItem($_REQUEST['data'], $USER->GetId());
                   $responce = getSubtask($task->getData());
                    echo json_encode($responce);
                    // Изменение задачи
    elseif ($_REQUEST['type']=="changeTasks"):

        // Функция проверки свободного времении на подзадачи
        function checkfreeTime($item, $key){
            $result=false;
                $res_id="";
            $restask=CTasks::GetList(Array("TITLE" => "ASC"), Array("ID" => $key));
                while ($arTask = $restask->GetNext())
                {
                    $res_id = $arTask['RESPONSIBLE_ID'];
                }
                $date=date("d.m.Y",strtotime($item['date']));
                $timeStart =strtotime($date.' '.$item['startTime']);
                $timeEnd=strtotime("+".$item['time']." hours",strtotime($date.' '.$item['startTime']));
                $subtask = CTasks::GetList(Array("TITLE" => "ASC"), Array("RESPONSIBLE_ID" => $res_id,
                    "STATUS" => 2,
                    '>=START_DATE_PLAN' => $date.' 00:00',
                    '<=END_DATE_PLAN' => $date.' 23:99'));
                while ($arTask = $subtask->GetNext())
                {
                    if((int)$arTask['ID']!==$key) {
                        if ($timeStart > strtotime($arTask['START_DATE_PLAN']) && $timeStart < strtotime($arTask['END_DATE_PLAN'])):
                            $result = true;
                        endif;
                        if ($timeEnd > strtotime($arTask['START_DATE_PLAN']) && $timeEnd < strtotime($arTask['END_DATE_PLAN'])):
                            $result = true;
                        endif;
                        if ($timeStart <= strtotime($arTask['START_DATE_PLAN']) && $timeEnd >= strtotime($arTask['END_DATE_PLAN'])):
                            $result = true;
                        endif;
                    }
                }
            return $result;
        }

        $responce="error";
        $parentId;
        $DescriptionText = GetMessage('setting_was_change');
        $test=[];
        foreach ($_REQUEST['data'] as $key => $item):

            if(!checkfreeTime($item, $key)):

            $arFields = Array(
                    "DURATION_PLAN"=>$item['date'],
                    "DURATION_TYPE"=>"hours",
                    "TIME_ESTIMATE"=>$item['time']*3600,
                    "START_DATE_PLAN"=>date("d.m.Y  H:i",strtotime($item['date']." ".$item['startTime'])),
                    "END_DATE_PLAN"=>date("d.m.Y  H:i",strtotime("+".$item['time']." hours",strtotime($item['date']." ".$item['startTime'])))
            );
            if (CModule::IncludeModule("tasks")):
                $ID = $key;
                $obTask = new CTasks;
                $rsTask = CTasks::GetByID($ID);
                if ($arTask = $rsTask->GetNext())
                {
                    $parentId = $arTask["PARENT_ID"];
                }
                $success = $obTask->Update($ID, $arFields);
                if ($success) {
                    $responce="success";
                    $DescriptionText=$DescriptionText.
                    "<br>".GetMessage('task_number').$ID." ".GetMessage('start').": ".date("d.m.Y  H:i",strtotime($item['date']." ".$item['startTime'])).
                    ",<br> ".GetMessage('end').": ".date("d.m.Y  H:i",strtotime("+".$item['time']." hours",strtotime($item['date']." ".$item['startTime']))).
                    ", <br>".GetMessage('time_for_task')." ".$item['time']."ч.";

                } else {
                    if ($e = $APPLICATION->GetException())
                        $responce="error";
                }
            endif;

            else:
                $responce="error";
                endif;
        endforeach;
//        CTaskComments::Add($parentId,  1, $DescriptionText);
        echo  $responce;
         elseif ($_REQUEST['type']=="changeUserSetting"):
             $responce="error";
             $user = new CUser;
             foreach ($_REQUEST['data'] as $key => $item):
                 if($item['checked']=="false"){
                 $item['checked']= false;
             }
                 $fields = Array(
                     "UF_TASK_STATUS" => $item['checked'],
                     "UF_TASK_WORK_TIME" => $item['time']
                 );
                 $success = $user->Update($key, $fields);
                 if ($success) {
                     $responce="success";

                 } else {
                     if ($e = $APPLICATION->GetException())
                         $responce="error";
                 }
         endforeach;
                 echo $responce;
    else:
        if($_REQUEST['href']):
        $core = new ClassCore($USER, getParam($_REQUEST['href']));
        else:
            $core = new ClassCore($USER, false );

        endif;
        echo json_encode($core);
    endif;
endif;
?>