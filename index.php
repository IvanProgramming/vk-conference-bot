
<?php
/*  
    Скрипт от Ивана Власова
    Вконтакте - vk.com/svobodaetorabstvo
    
    Для работы скрипта нужен VK CallBack Api Версии не ниже 5.50
    
    Скрипт для бота-статиста беседы
    Этот бот умеет
    -Вести статистику для беседы
    -Отправлять их в беседу
*/
#VARIABLES Переменные для работы скрипта
#          Если не заполнить, то работать не будет
$group_id = /*ID сообщества*/; 
$confirmation_token = /*строка подтверждения*/;
$token = /*токен*/;
$stat_message = /*сообщение для того чтобы получить статистику*/;
$DB = array(
    'host' => /*хост базы данных*/,
    'user' => /*имя пользователя*/,
    'password' => /*пароль*/,
    'db_name' => /*имя базы данных*/
);
#VARIABLES
//Внешняя библиотека для работы с API Вконтакте
//Библиотека by slmatthew
//Должна лежать в той же директории что и этот скрипт
include "vk.php";
//Если ничего не нету, то ничего не делаем
if (!isset($_REQUEST))
{
    return;
}
//Создаем объект бота
$vk = new VKBot($group_id, $token);
//Конектимся к базе данных
$link = mysqli_connect($DB['host'], $DB['user'], $DB['password'], $DB['db_name']);
if (!$link) //Если вдруг не приконектилось то отключаемся
{
    echo "Error: Unable to connect to MySQL." . PHP_EOL;
    echo "Debugging errno: " . mysqli_connect_errno() . PHP_EOL;
    echo "Debugging error: " . mysqli_connect_error() . PHP_EOL;
    exit;
}
//Декодируем Json со входа
$data = json_decode(file_get_contents('php://input'));
switch ($data->type)
{
    //Если собыитие - confirmation
    case 'confirmation':
        //Отправляем строку подтверждения
        echo $confirmation_token;
    break;
    //Если пришло новое сообщения
    case 'message_new':
        //Вытаскиваем из запроса ID юзера
        $user_id = $data
            ->object->from_id;
        //Вытаскиваем из запроса сообщение
        $message = $data
            ->object->text; 
        //Вытаскиваем из запроса ID беседы
        $peer_id = $data
            ->object->peer_id;
        //Считаем количество символов(предварительно убирая пробелы)
        $chars_count = mb_strlen(str_replace(' ','',$message));
        //Считаем количество слов
        $words_count = count(explode(' ', $message));
        //Если сообщение пришло из беседы
        if (strlen("{$peer_id}") > 9)
        {   //Исполняем SQL запрос
            if ($result_chat = $link->query('SELECT * FROM `chats` WHERE id_vk =' . $peer_id))
            {   //Вытаскиваем данные из запроса
                $res = $result_chat->fetch_array();
                //Если беседа есть в базе данных
                if ($res)
                {   //Если текст сообщения не текст для запроса статистики
                    if ($message != $stat_message)
                    {   //Обновляем данные о сообщениях беседы в базе данных с помощью SQL запроса
                        $link->query('UPDATE `chats` SET `all_msg`=' . ($res['all_msg'] + 1) . ',`all_words`=' . ($res['all_words'] + $words_count) . ',`all_chars`=' . ($res['all_chars'] + $chars_count) . ' WHERE `id`=' . $res['id']);
                    }
                }
                //Если беседы нету в базе данных
                else
                {   //Добавляем беседу в базу данных с помощью SQL запроса
                    $link->query("INSERT INTO `chats`(`id_vk`, `all_msg`, `all_words`, `all_chars`, `debug`) VALUES ($peer_id, 1, $words_count, $chars_count, 1)");
                }
            }
            //Исполняем SQL запрос
            if ($result_members = $link->query('SELECT * FROM `members` WHERE `id_vk` = ' . $user_id . ' AND `id_chat` = ' . $peer_id))
            {   //Вытаскиваем данные из запроса
                $res_member = $result_members->fetch_array();
                //Если пользователь есть в базе данных
                if ($res_member)
                {   //Если текст сообщения не текст для запроса статистики
                    if ($message != $stat_message)
                    {   //Обновляем данные о сообщениях пользователя в базе данных с помощью SQL запроса
                        $link->query('UPDATE `members` SET `all_msg`=' . ($res_member['all_msg'] + 1) . ',`all_words`=' . ($res_member['all_words'] + $words_count) . ',`all_chars`=' . ($res_member['all_chars'] + $chars_count) . ' WHERE `id_vk` = ' . $user_id . ' AND `id_chat` = ' . $peer_id);
                    }
                }
                //Если пользователя нет в базе данных
                else
                {   //Добавляем пользователя в базу данных с помощью SQL запроса   
                    $link->query("INSERT INTO `members`(`id_vk`, `id_chat`, `all_msg`, `all_words`, `all_chars`) VALUES ($user_id, $peer_id, 1, $words_count, $chars_count)");
                }
            }

        }
        //Если попросили статистику
        if ($message == $stat_message)
        {   //Переменные для отправки статистики в беседу
            $top = "";
            $ids = array();
            $msgs = array();
            //Выполняем SQL запрос для пользователей беседы
            $best = $link->query('SELECT * FROM `members` WHERE `id_chat` = ' . $peer_id . ' ORDER BY `all_msg` DESC LIMIT 10');
            //Выполняем SQL запрос для беседы
            $conference_stat = $link->query('SELECT * FROM `chats` WHERE `id_vk` = ' . $peer_id);
            //Вытаскиваем данные из запроса
            $stat = $conference_stat->fetch_array();
            //Поочередно вытаскиваем строки из ответа базы данных
            while ($best_row = $best->fetch_array())
            {   //Добавляем в конец массива с айдишниками ID человека
                array_push($ids, $best_row['id_vk']);
                //Добавляем в конец массива с общим количеством сообщений для каждого человека его количество сообщений 
                array_push($msgs, $best_row['all_msg']);
            }
            //Запрашиваем данные о пользователях у ВК и декодируем их
            $name_info = json_decode(file_get_contents("https://api.vk.com/method/users.get?user_ids=" . implode(",", $ids) . "&access_token={$token}&v=5.0"));
            //Состовляем топ пользователей
            for ($i = 0;$i < 10;$i++)
            {   //Мало ли, пользователей в беседе меньше 10
                if (isset($msgs[$i]))
                {
                    $first_name = $name_info->response[$i]->first_name;
                    $last_name = $name_info->response[$i]->last_name;
                    $top = $top . ($i + 1) . ". " . $first_name . " " . $last_name . " - " . $msgs[$i] . ' (' . round(($msgs[$i] / $stat['all_msg']) * 100) . '%)' . PHP_EOL;
                }
            //Собираем всё это в одно сообщение и отправляем
            $vk->send($peer_id, "Общее количество сообщений с момента добавления бота в беседу - {$stat['all_msg']}" . PHP_EOL . "Общее количество слов {$stat['all_words']}" . PHP_EOL . "Общее количество символов {$stat['all_chars']}" . PHP_EOL . "Средняя длинна сообщения - " . (round($res['all_chars'] / $stat['all_msg'])) . PHP_EOL . "Самые активные 10 участников (участниками считаются все те, кто писал после того как был добавлен бот)" . PHP_EOL . PHP_EOL . $top);
        }
        //Отправляем ok серверу ВКонтакте
        echo ("ok");
    break;
}
?>
