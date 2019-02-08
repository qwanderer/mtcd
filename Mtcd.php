<?php


function is_cli(){
    return (PHP_SAPI==='cli' OR defined('STDIN'));
}

function d($data=[], $function="print_r"){
    if(is_array($data) or is_object($data)){
        $web_suff = ['<pre>', '</pre>'];
        $cli_suff = ["", ""];
        $curr_suff = (is_cli())?$cli_suff:$web_suff;
        echo $curr_suff[0];
        $function($data);
        echo $curr_suff[1];
    }else{
        echo (is_cli())?"\n":"<br>";$function($data);
    }
} // func

function dd($data=[], $function="print_r"){d($data, $function);die;} // func

function wd($text, $function="print_r"){
    d($text, $function);
    while(1){sleep(1);}
} // func

class Mtcd
{

    public $verbose = 1; // 1 - d() - будет видно

    public $threads = 1; // кол-во потоков скачивания (кол-во курлов)

    public $min_file_size=1000;

    public $getRemoteFileSize_attempts=3; // кол-во попыток чтобы определить размер файла до скачивания

    public $useragent = 'Mozilla/5.0 (Windows NT 6.3; Win64; x64; rv:57.0) Gecko/20100101 Firefox/57.0';

    public $url; // url to download

    public $output_file_name; // file name output

    public $output_folder; // folder to output

    public $curl_cmd_flag = '/B'; // терминалы с курлами будут скрыты

    public $curls_end_time_limit = 2000; // лимит - сколько ждать завершения саомго большого курл-потока (в секундах)

    public $get_chunk_handler_attempts = 2; // кол-во попыток за которые нужно получить handler на чанк чтобы слить его в результирующий файл

    public $setRemoteFileSize_callback; // ф-я которая вызовется после получения $setRemoteFileSize

    public $saveReadyPercent_callback;  // ф-я которая будет вызываться на каждой итерации waitWhileCurlsAreEnd



    public $remote_file_size; // сюда запишется заявленый размер скачиваемого файла

    public $ready_percent=0; // готовность скачивания
    private $old_ready_percent=0; // Предыдущий показатель готовности скачивания (для проверки что процент меняется)
    private $ready_percent_same=0; // счетчик - сколько раз процент не менялся
    private $ready_percent_same_limit=100; // лимит перед остановкой скачивания

    private $default_chunk_size; // размер чанка на каждый курл процесс
    private $chunks_arr_right_order = []; // сюда рассчитаются будущие чанки

    private $chunks_arr = []; // сюда рассчитаются будущие чанки с сортировкой

    private $output_full_path = ""; // full path output with file_name







    private function d($data, $function="print_r"){
        if($this->verbose != true){return false;}

        if(is_array($data) or is_object($data)){
            $web_suff = ['<pre>', '</pre>'];
            $cli_suff = ["", ""];
            $curr_suff = (is_cli())?$cli_suff:$web_suff;
            echo $curr_suff[0];
            $function($data);
            echo $curr_suff[1];
        }else{
            echo (is_cli())?"\n":"<br>";$function($data);
        }
    }


    /**
     * Сеттер опций
     * @param $opt
     * @param null $v
     * @return $this
     */
    public function setOpt($opt, $v=null){
        if(is_array($opt)){
            foreach($opt as $key=>$value){
                $this->$key = $value;
            }
        }else{
            $this->$opt=$v;
        }
        return $this;
    } // func






    /**
     * Главный метод
     * @throws Exception
     */
    public function download(){

        $this->setFullPath();

        $this->setRemoteFileSize();
        is_callable($this->setRemoteFileSize_callback) && call_user_func($this->setRemoteFileSize_callback, $this);

        if($this->remote_file_size < $this->min_file_size) {
            throw new Exception("remote_file_size is too small");
        }

        $this->setDefaultChunkSize();

        $this->calculateChunks();

        $this->init_downloadCurls();

        try{
            $this->waitWhileCurlsAreEnd();
            $this->checkChunksSizes();
            $this->deChunking();
            $this->checkFinalSize();
        }catch (Exception $e){
            $this->deleteChunks();
            throw new Exception($e->getMessage());
        }

    } // func


    /**
     * Метод чистит чанки в случае если они не собрались
     */
    private function deleteChunks()
    {
        foreach ($this->chunks_arr as $chunk)
        {
            if(file_exists($chunk['full_path_to_chunk'])){
                @unlink($chunk['full_path_to_chunk']);
            }
        } // foreach
    } // func


    /**
     * Метод генерит output_full_path из output_folder и output_file_name
     */
    private function setFullPath()
    {
        // TODO unix?
        $output_full_path = trim($this->output_folder, "\\ /").DIRECTORY_SEPARATOR.trim($this->output_file_name, "\\ /");
        $this->output_full_path = str_replace(["/", "\\\\"], "\\", $output_full_path);
    }


    /**
     * Метод рассчитывает будущие чанки
     */
    private function calculateChunks()
    {
        $this->chunks_arr=[];
        for ($thread_number = 0; $thread_number < $this->threads; $thread_number++) {

            $chunk_start = $thread_number * $this->default_chunk_size;
            $chunk_end   = ($thread_number+1) * $this->default_chunk_size;

            if($this->isLastThread($thread_number)){
                $chunk_size = $chunk_end - $chunk_start + 1;
                $chunk_end = "";
            }else{
                // если это не последний поток - то надо отнять 1 от chunk_end так как следующий поток начнет качать именно с него
                // пример : remote_file_size = 13 ; threads=2
                // thread 1 : 0-5 bytes
                // thread 2 : 6-13 bytes
                $chunk_end--;
                $chunk_size = $chunk_end - $chunk_start + 1;
            }

            $this->chunks_arr[] = [
                'full_path_to_chunk' => $this->getFullChunkName($thread_number),
                'start' => $chunk_start,
                'end' => $chunk_end,
                'size' => $chunk_size
            ];
        } // for

        $this->chunks_arr_right_order = $this->chunks_arr;

        // сразу отсортируем массив с чанками так чтобы бОльший чанк был первым
        array_multisort(array_column($this->chunks_arr, "size"), SORT_DESC, $this->chunks_arr);
    } // func







    /**
     * Возвращает полный путь до чанка исходя из номера потока
     * @param $thread_number
     * @return string
     */
    private function getFullChunkName($thread_number)
    {
        return $this->output_full_path.".c".($thread_number + 1);
    }



    /**
     * Метод рассчитывает размер чанка исходя из кол-ва потоков и заявленного размера remote_file_size
     */
    private function setDefaultChunkSize()
    {
        $this->default_chunk_size = floor($this->remote_file_size / $this->threads);
    }




    /**
     * Метод запускает $this->threads курлов на скачивание файла
     */
    private function init_downloadCurls()
    {
        foreach($this->chunks_arr as $k=>$chunk_info){
            $range = $chunk_info['start']."-".$chunk_info['end'];
            $this->initCurlProc($range, $chunk_info['full_path_to_chunk']);
        } // foreach
    } // func




    /**
     * Метод возвращает true если номер переданного поток последний
     * @param $thread_number
     * @return bool
     */
    private function isLastThread($thread_number){
        return ($this->threads-$thread_number===1);
    } // func


    /**
     * Метод запускает поток
     * @param $range
     * @param $full_path_to_chunk
     */
    private function initCurlProc($range, $full_path_to_chunk){
        $cmd = "curl -k --range $range --retry 3 --retry-delay 3 --location --user-agent \"" . $this->useragent . "\" --output \"$full_path_to_chunk\" \"$this->url\"";
        $this->d($cmd);
        pclose(popen('start '.$this->curl_cmd_flag.'  "Download process for ' . $this->output_file_name . '" ' . $cmd . ' > nul 2>nul ', "r"));
    } // func


    /**
     * Метод синхронно ждет пока не изчезнут все курл процессы по данному файлу
     */
    private function waitWhileCurlsAreEnd(){
        sleep(3);
        $started_at = time();
        $biggest_chunk = $this->chunks_arr[0];
        do {
            if(time() - $started_at > $this->curls_end_time_limit){
                throw new Exception("curls_end_time_limit");
            }

            clearstatcache();

            $this->ready_percent = round(@filesize($biggest_chunk['full_path_to_chunk']) / $biggest_chunk['size'] * 100,2);
            is_callable($this->saveReadyPercent_callback) && call_user_func($this->saveReadyPercent_callback, $this);

            try{
                $this->checkReadyPercentOnUnicOrFail();
            }catch (Exception $e){
                throw new Exception($e->getMessage());
            }

            if(is_cli()){ $this->d($this->ready_percent."% same {$this->ready_percent_same} / {$this->ready_percent_same_limit}"); }

            if ($this->countCurlProcesses() < 1) break;
            sleep(1);
        } while (1);

    } // func


    /**
     * Метод проверяет что процент скачивания меняется
     */
    private function checkReadyPercentOnUnicOrFail()
    {
        if($this->ready_percent_same > $this->ready_percent_same_limit){
            throw new Exception("old_ready_percent_same_limit");
        }

        if($this->ready_percent == $this->old_ready_percent){
            $this->ready_percent_same++;
        }else{
            $this->old_ready_percent = $this->ready_percent;
            $this->ready_percent_same=0;
        }
    } // func


    /**
     * Метод проверяет что все результирующие размеры чанков соответствуют заявленным
     * @throws Exception
     */
    private function checkChunksSizes(){
        $this->d("checkChunksSizes");
        clearstatcache();

        foreach($this->chunks_arr as $chunk){
            $real_chunk_size = filesize($chunk['full_path_to_chunk']);
            $this->d("calculated: ".$chunk['size']." vs real: ".$real_chunk_size);

            if($real_chunk_size < ($chunk['size']*0.9) || $real_chunk_size > ($chunk['size']*1.1)){
                throw new Exception("not valid chunk size");
            }
        }
    } // func


    /**
     * Метод слепляет все чанки в 1 файл
     * @throws Exception
     */
    private function deChunking(){
        $result_file = fopen($this->output_full_path, "wb+");
        foreach($this->chunks_arr_right_order as $chunk){
            if(!file_exists($chunk['full_path_to_chunk'])){
                throw new Exception("chunk {$chunk['full_path_to_chunk']} not found");
            }

            try{
                $chunk_file_handler = $this->getChunkFileHandlerForce($chunk);
            }catch (Exception $e){
                throw new Exception($e->getMessage());
            }

            while (!feof($chunk_file_handler)) {
                $t = fread($chunk_file_handler, 1048576);
                if (!$t) break;
                fwrite($result_file, $t);
            }
            fclose($chunk_file_handler);
            unlink($chunk['full_path_to_chunk']);

        } // foreach

    } // func

    /**
     * Метод пытается несоклько раз сделать хэндлер чанка
     * В случае успеха - возвращает хэндлер
     * В случае ошибки - exception
     * @param $chunk
     * @return resource
     * @throws Exception
     */
    private function getChunkFileHandlerForce($chunk)
    {
        $chunk_file_handler=false;
        for($i=0; $i<$this->get_chunk_handler_attempts; $i++){
            if($chunk_file_handler = fopen($chunk['full_path_to_chunk'], "r+")){
                break;
            }
            sleep(3);
        } // for
        if($chunk_file_handler){
            return $chunk_file_handler;
        }else{
            throw new Exception("cant create chunk handler for {$chunk['full_path_to_chunk']}");
        }
    } // func



    /**
     * Метод проверяет что размер получившегося в результате файла совпадает с заявленным размером с погрешностью 10%
     * Не уверен на счет погрешности
     * @throws Exception
     */
    private function checkFinalSize(){
        clearstatcache();
        $result_file_size = @filesize($this->output_full_path);

        if($result_file_size < ($this->remote_file_size*0.9) || $result_file_size > ($this->remote_file_size*1.1)){
            throw new Exception("Not valid result file size");
        }
    } // func


    /**
     * Метод считает сколько сейчас есть запущеных curl-процессов с именем файла
     * @return int
     */
    private function countCurlProcesses()
    {
        $k = 0;
        exec('wmic process where name="curl.exe"', $out);
        if (count($out) > 0) {
            foreach ($out as $row) {
                if (strlen($row) > 2 and strpos($row, $this->output_file_name) !== false) {
                    $k++;
                }
            } // foreach
        }
        return $k;
    } // func


    /**
     * Меотд узнает remote_file_size не скачивая файл
     */
    private function setRemoteFileSize()
    {
        $result = -1;
        $attempts = 0;
        while($attempts++ <=$this->getRemoteFileSize_attempts){
            $curl = curl_init($this->url);
            curl_setopt_array($curl, [
                CURLOPT_POST => false,        //TODO may be important
                CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 6.3; Win64; x64; rv:57.0) Gecko/20100101 Firefox/57.0',
                CURLOPT_RETURNTRANSFER => true,     // TODO may be important
                CURLOPT_HEADER => true,    // don't return headers
                CURLOPT_FOLLOWLOCATION => true,     // follow redirects
                CURLOPT_AUTOREFERER => true,     // set referer on redirect
                CURLOPT_CONNECTTIMEOUT => 120,      // timeout on connect
                CURLOPT_TIMEOUT => 120,      // timeout on response
                CURLOPT_MAXREDIRS => 10,       // stop after 10 redirects
                CURLOPT_NOBODY => true,
                CURLOPT_SSL_VERIFYPEER=>false,
            ]);
            $data = curl_exec($curl);
            if($data == false){ $this->d('Curl error:' . curl_error($curl)); }
            curl_close($curl);

            if ($data) {
                $content_length = "unknown";
                $status = "unknown";

                if (preg_match("/^HTTP\/1\.[01] (\d\d\d)/", $data, $matches)) {
                    $status = (int)$matches[1];
                }

                if (preg_match_all("/Content-Length: (\d+)/", $data, $matches)) {
                    $content_length = array_pop($matches[1]);
                }

                // http://en.wikipedia.org/wiki/List_of_HTTP_status_codes
                if ($status == 200 || ($status > 300 && $status <= 308)) {
                    $result = $content_length;
                    if($result>0){ break; }
                }
            }
            sleep(1);
        } // while
        $this->remote_file_size = $result;
    } // func









} // class






$ddd = new Mtcd();
d($ddd);

$setRemoteFileSize_callback = function($obj){
    d("from call back ".$obj->url);
};


try{
    $ddd
        ->setOpt('url', "http://path/to/file.mp4")
        ->setOpt('output_folder', "D:\\")
        ->setOpt('output_file_name', "1.mp4")
        ->setOpt('threads', 5)
        ->setOpt('setRemoteFileSize_callback', $setRemoteFileSize_callback)
        ->download();
}catch (Exception $e){
    d($e->getMessage());
}

d($ddd);