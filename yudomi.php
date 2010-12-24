<?php
require_once("config/config.php");
require_once("lib/vendor/twitteroauth/twitteroauth.php");
require_once("lib/vendor/spyc/spyc.php");

/**
 * ゆどみBot
 *
 * 定期的にゆどうふに話しかけるだけの簡単なお仕事をします（※愛をこめて）
 *
 * @author yuchimiri
 * @version 1.0
 */
class Yudomi {
    /**
     * home_timeline取得URL
     */
    const HOME_TIMELINE_URL = "http://twitter.com/statuses/home_timeline.xml";

    /**
     * user_timeline取得URL
     */
    const USER_TIMELINE_URL = "http://twitter.com/statuses/user_timeline.xml";

    /**
     * ユーザ情報取得URL
     */
    const USER_SHOW_URL     = "http://twitter.com/users/show.xml";

    /**
     * POST用URL
     */
    const STATUS_UPDATE_URL = "https://twitter.com/statuses/update.xml";

    /**
     * データディレクトリ 
     */
    const DATA_DIR = "./data/";

    /**
     * 発言リストが書かれたファイル
     */
    const MESSAGES_FILENAME = "messages.yml";

    /**
     * イベントファイル
     */
    const EVENTS_FILENAME = "events.yml";
   
    /**
     * タイムラインの取得を開始するIDを記録したファイル
     */
    const SINCE_ID_FILENAME = "./tmp/since_id";

    /**
     * タイムラインを取得する最大数
     */
    const TIMELINE_COUNT    = 30;
    
    /**
     * OAuthオブジェクト
     *
     * @var TwitterOAuth
     */
    public $toa;

    /**
     * ゆどみのプロフィール
     *
     * @var array
     */
    public $yudomi;

    /**
     * 発言リスト
     *
     * @var array
     */
    public $messages = array();

    /**
     * 現在時刻（時分）
     *
     * @var string
     */
    public $time = "";

    /**
     * 一回replyした人のアカウントを残しておく用
     *
     * @var array
     */
    public $uniq_id = array();
    
    /**
     * コンストラクタ
     */
    public function  __construct() {
        // 認証に必要なKEYが設定されているか確認
        if (CONSUMER_KEY == "" || CONSUMER_SECRET == "" || ACCESS_TOKEN == "" || ACCESS_TOKEN_SECRET == "") {
            echo "Please set CONSUMER and ACCESS keys.";
            exit;
        }

        // OAuthオブジェクト作成
        $this->toa = new TwitterOAuth(CONSUMER_KEY, CONSUMER_SECRET, ACCESS_TOKEN, ACCESS_TOKEN_SECRET);
        
        // 自分のプロフィール取得
        $user = $this->toa->oAuthRequest(self::USER_SHOW_URL, "GET", array("screen_name"=>KANOJO));
        $xml  = simplexml_load_string($user);
        $this->yudomi = $this->getProfile($xml);

        $this->loadMessageFromYaml(EVENT);

        // 現在時刻（時分）取得
        $this->time = date("Hi");

    }

    /**
     * 時間ごとのつぶやき
     *
     * 発言リストに定義された時刻とテキストをもとに発言する
     */
    public function lovecall() {
        // 発言リストを取得
        $messages = $this->getMessages("lovecall");

        // 現在時刻（時分）と発言リストのキーが一致した場合
        if (array_key_exists($this->time, $messages)) {
            $rand_key = array_rand($messages[$this->time], 1);

            $message = "@".KARESHI." ".$messages[$this->time][$rand_key];

            $this->tweet($message);
            $this->uniq_id[] = KARESHI;
        }
        
        return;
    }

    /**
     * 反応するだけ
     *
     * タイムラインに自分の名前を見つけたら返事をする
     */
    public function response() {
        $tweets = array();

        // home_timelineを取得
        $since_id = $this->getSinceid();
        $timeline = $this->getHomeTimeline($since_id, TIMELINE_COUNT);

        foreach($timeline as $status){
            // 自分の名前が入っている発言があれば反応する。今回既にリプライしたユーザにはしない。
            if ($status["screen_name"] != $this->yudomi["screen_name"] && preg_match("/".$this->yudomi["name"]."/", $status["text"]) && !in_array($status["screen_name"], $this->uniq_id)) {
                $messages = $this->getMessages("reply");
                $rand_key = array_rand($messages, 1);
                $message = "@".$status["screen_name"]." ".$messages[$rand_key];

                $this->tweet($message, (string)$status["status_id"]);
                $this->uniq_id[] = $status["screen_name"];
            }
            $this->saveSinceid($status["status_id"]);
        }

        return;
    }

    /**
     * @自分の発言があったときのリプライ
     *
     * @todo 実装
     */
    public function reply() {}

    /**
     * つぶやく
     *
     * @param string $message               つぶやき
     * @param int    $in_reply_to_status_id 返信元のstatus_id
     */
    public function tweet($message, $in_reply_to_status_id = null) {
        if (is_null($in_reply_to_status_id)) {
            $this->toa->oAuthRequest(self::STATUS_UPDATE_URL, "POST", array("status"=>$message));
        } else {
            $this->toa->oAuthRequest(self::STATUS_UPDATE_URL, "POST", array("status"=>$message, "in_reply_to_status_id"=>$in_reply_to_status_id));
        }
    }
    
    /**
     * ホームタイムラインの取得
     *
     * @param int $since_id タイムラインの取得を開始するID
     * @param int $count    タイムラインの取得数
     */
    public function getHomeTimeline($since_id = 1, $count = 10) {
        $result   = array();

        $timeline = $this->toa->oAuthRequest(self::HOME_TIMELINE_URL, "GET", array("since_id"=>$since_id, "count"=>$count));
        $xml      = simplexml_load_string($timeline);

        // 古いtweetが前にくるように入れ替えつつ、statusだけを取得
        foreach ($xml->status as $item) {
            // xmlデータは扱いやすいように配列にする
            $status = $this->getStatus($item);
            array_unshift($result, $status);
        }
        
        return $result;

    }
    /**
     * ユーザタイムラインの取得
     *
     * @param string $screen_name ユーザアカウント
     * @param int    $since_id    タイムラインの取得を開始するID
     * @param int    $count       タイムラインの取得数
     *
     */
    public function getUserTimeline($screen_name, $since_id = 1, $count = 10) {
        $result   = array();

        $timeline = $this->toa->oAuthRequest(self::USER_TIMELINE_URL, "GET", array("screen_name"=>$screen_name, "since_id"=>$since_id, "count"=>$count));
        $xml      = simplexml_load_string($timeline);

        // 古いtweetが前にくるように入れ替えつつ、statusだけを取得
        foreach ($xml->status as $item) {
            // xmlデータは扱いやすいように配列にする
            $status = $this->getStatus($item);
            array_unshift($result, $status);
        }

        return $result;

    }

    /**
     * 発言リストから指定されたキーの下にあるものを返す
     *
     * @param string key リストのキー名
     */
    public function getMessages($name) {
        $result = array();

        if (array_key_exists($name, $this->messages)) {
            $result = $this->messages[$name];
        }
        
        return $result;
    }

    /**
     * 取得開始IDを取得する
     *
     * @return int 取得開始ID
     */
    private function getSinceid() {
        $since_id = trim(file_get_contents(self::SINCE_ID_FILENAME));
        $result   = (is_numeric($since_id))?$since_id:1;

        return $result;
    }

    /**
     * 開始IDを保存する
     *
     * @param int $since_id
     */
    private function saveSinceid($since_id) {
        file_put_contents(self::SINCE_ID_FILENAME, $since_id);
    }

    /**
     * status情報を配列に入れて返します
     *
     * @param  xmlObject $data status情報
     * @return array           配列に入ったstatus情報
     */
    private function getStatus($data) {
        // 空のstatus情報を生成
        $status = array();

        $status["created_at"]  = $data->created_at;         // つぶやいた日時
        $status["status_id"]   = $data->id;                 // つぶやきのID
        $status["text"]        = $data->text;               // つぶやき
        $status["user_id"]     = $data->user->id;           // ユーザID
        $status["screen_name"] = $data->user->screen_name;  // HN
        $status["name"]        = $data->user->name;         // name

        return $status;
    }

    /**
     * ユーザ情報を配列に入れて返します
     *
     * @param  xmlObject $data ユーザ情報
     * @return array           配列に入ったユーザ情報
     */
    private function getProfile($data) {
        // 空のユーザ情報を生成
        $profile = array();

        $profile["id"]          = $data->id;          // つぶやいた日時
        $profile["name"]        = $data->name;        // つぶやきのID
        $profile["screen_name"] = $data->screen_name; // つぶやきのID
        $profile["location"]    = $data->location;    // つぶやき
        $profile["description"] = $data->description; // ユーザID

        return $profile;
    }

   /**
    * メッセージを取得
    * 
    * @param boolean $use_event
    */
   private function loadMessageFromYaml($use_event) {
       $yaml = null;
       if ($use_event) {
          $events = Spyc::YAMLLoad(self::DATA_DIR . self::EVENTS_FILENAME);
	  $date = date('m-d');
          if (array_key_exists($date, $events['events'])) {
             $yaml = $events['events'][$date];
          }
       }

       if (is_null($yaml)) {
           $yaml = self::MESSAGES_FILENAME;
       }

       $this->messages = Spyc::YAMLLoad(self::DATA_DIR . $yaml);
   }
}
