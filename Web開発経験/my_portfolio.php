<?php
$str_url = htmlspecialchars($_SERVER['REQUEST_URI'], ENT_QUOTES,'UTF-8');

$str_key = explode('?', $str_url)[1];
$order_list = explode('&', $str_key);
$ck_name = true; $ck_amount = true; $ck_price = true;

//parameter checkで入力される値を検閲(正規表現による制御)することで、SQL_injection対策を行う。
foreach($order_list as $equa){
  $list = explode('=',$equa);
  $name = $list[0];
  $param = $list[1];
  if($name=="name"){
    $ck_name = preg_match('/^[0-9]*[a-zA-Z_]+[0-9]*[a-zA-Z_]*[0-9]*$/' ,$param);
  }else if($name=="amount"){
    $ck_amount = preg_match('/^[0-9]*$/' ,$param);
  }else if($name=="price"){
    $ck_price =  preg_match('/^[0-9]*$/',$param);
  }
}
//SQL_injectionの対策: 入力-validation
if(!($ck_name and $ck_amount and $ck_price)){
  if(!$ck_name){   echo "name ";}
  if(!$ck_amount){ echo "amount ";}
  if(!$ck_price){  echo "price ";}
  echo "-> ERROR!\n";
  exit;
}
//-----------------validation_check_clear　↓-----------------------

$dsn = 'mysql:dbname=test;host=localhost';
$user = 'root';
$pass = 'Y';
try{
  $link = new PDO($dsn,$user,$pass);
}catch (PDOException $e) {
    echo "接続失敗: " . $e->getMessage() . "\n";
    exit();
}


//機能判定
$function = explode('=',$order_list[0])[1];

//(4)売り上げチェック
if($function=="checksales"){
  $res = $link->query("select amount from stock where id=1");
  $sell = $res->fetchAll()[0][0];
  echo $sell."\n";
}

//(5)deleteallの処理
if($function=="deleteall"){
  $res = $link->query("delete from stock");

  //DBのid=1のamountを総売上を記録させる。
  $res = $link->query("insert into stock (id ,name ,amount)values(1,'sum_sell' ,0)");
  echo "全削除実行完了！\n";
}

//-------------------------------------------------------------
if(count($order_list)>=2){
  $name = explode('=',$order_list[1])[1];
}

//(1)在庫の追加処理
if($function=="addstock"){
  $sql = "select id, amount from stock where name=:name";
  $sth = $link->prepare($sql);
  $sth -> bindParam(':name',$name);
  $sth->execute();
  $rows = $sth->fetchAll();

  if(count($order_list)==2){
    if($rows){
      $id = (int)$rows[0][0];
      $amount = (int)$rows[0][1] + 1;
      $sth = $link->prepare("update stock set amount=:amount where id=:id");
      $sth->bindParam(':amount',$amount, PDO::PARAM_INT);
      $sth->bindParam(':id',$id, PDO::PARAM_INT);
    }else{
      $sth = $link->prepare("insert into stock (name,amount)values(:name ,1)");
      $sth->bindParam(':name',$name);
    }
    $sth->execute();
  }else if(count($order_list)==3){
    $amount = (int)explode('=',$order_list[2])[1];
    if($rows){
      $id = (int)$rows[0][0];
      $amount = (int)$rows[0][1] + $amount;
      $sth = $link->prepare("update stock set amount=:amount where id=:id");
      $sth->bindParam(':id',$id,PDO::PARAM_INT);
    }else{
      $sth = $link->prepare("insert into  stock (name,amount)values(:name,:amount)");
      $sth->bindParam(':name',$name);
    }
    $sth->bindParam(':amount',$amount,PDO::PARAM_INT);
    $sth->execute();
  }
  echo "在庫追加処理 - 実行完了!\n";
  exit();
}



//(2)在庫チェック
if($function=="checkstock"){
  $res = $link->query("select name,amount from stock where id != 1 and amount!=0");
  $s = "";
  $rows = $res->fetchAll();
  foreach($rows as $row){
    $s = $s.$row[0].":".$row[1]."<>";
  }
  if($s==""){
    echo "nothing data.\n";
    exit;
  }
  $lines = explode('<>',$s);
  if(count($order_list)==1){
    usort($lines,"strcasecmp");
    foreach($lines as $line){
      if(!strpos($line,':')){
        continue;
      }
      echo $line."\n";
    }
  }else if(count($order_list)==2){
    foreach($lines as $line){
      $name2 = explode(':',$line)[0];
      if($name==$name2){
        echo $line."\n";
        break;
      }
    }
  }else{
    echo "COMMAND ERROR\n";
  }
}

//var_dump( (count($order_list)==3)and(explode('=',$order_list[2])[0]=="amount") );

//(3)販売
if($function=="sell"){
  $premise = (count($order_list)==3 and explode('=',$order_list[2])[0]=="amount");
  //DB内のamount(在庫数)を参照(在庫数の更新でどの分岐でも使用)
  $st_sth = $link->prepare("select amount from stock where name=:name");
  $st_sth -> bindParam(':name', $name);
  $st_sth->execute();
  $rows = $st_sth->fetchAll();

  if(!$rows){//在庫check->在庫が無い場合の例外処理
    echo $name." is no stock! \n";
    exit;
  }

  //both省略(amount=1,price=0) || price省略(取引ではなく、処分が目的の処理)
  if($premise || count($order_list)==2){
    $amount = 1;
    if($premise){
      $amount = (int)explode('=',$order_list[2])[1];
    }
    //1.残った商品の数が確定。
    $amount = (int)$rows[0][0] - $amount;
    if($amount<0){
      echo "在庫が足りません。\n";
      exit;
    }
  }
  //amount省略(取引処理)
  else if(count($order_list)==3 && explode('=',$order_list[2])[0]=="price"){
    //1.sell -> 売上の参照
    $res = $link->query("select amount from stock where id=1");
    //2.取引による売上金の変更
    $sell = (int)$res->fetchAll()[0][0];
    $price = (int)explode('=',$order_list[2])[1];
    $sell = $sell + $price;
    //3.DBへの売上金の反映
    $sth = $link->prepare("update stock set amount=:amount where id=1");
    $sth -> bindParam(':amount',$sell,PDO::PARAM_INT);
    $res = $sth->execute();

    //4.取引後の在庫数の確定
    $amount = (int)$st_res->fetchAll()[0][0] - 1;
  }
  //省略なし(取引処理)
  else if(count($order_list)==4){
    //1.sell -> 売上の参照
    $res = $link->query("select amount from stock where id = 1");
    //2.取引による売上金の変更
    $amount = (int)explode('=',$order_list[2])[1];
    $price = (int)explode('=',$order_list[3])[1];
    $sell = (int)$res->fetchAll()[0][0] + ($price * $amount);
    //3.DBへの売上金の反映
    $stmt = $link->prepare("update stock set amount=:amount where id=1");
    $stmt -> bindparam(':amount', $sell,PDO::PARAM_INT);
    $res = $stmt->execute();

    //4.取引後の在庫数の確定
    $amount = (int)$rows[0][0] - $amount;
  }else{
    echo "command error\n";
    exit;
  }

  //2 or 5.「処分後の在庫数をDBに反映」or「取引後の在庫(amount)をDBに反映」
  $stmt = $link->prepare("update stock set amount=:amount where name=:name");
  $stmt -> bindParam(':amount', $amount, PDO::PARAM_INT);
  $stmt -> bindParam(':name', $name);
  $stmt -> execute();
  echo "取引or処分が完了！\n";
}
?>
