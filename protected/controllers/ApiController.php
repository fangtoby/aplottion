<?php

class ApiController extends Controller
{
	/**
	 * 产品列表
	 */
	public function actionProductlist(){
		$models = Product::model()->findAll();
		$this->jsonSuccess($models);
	}
	/**
	 * 产品详情
	 */
	public function actionProductdetail($id)
	{
		$models = Detail::model()->findAllByAttributes(array(
			'pid'=>$id
		));
		$this->jsonSuccess($models);
	}
	
	public function actionLotterytest(){
		$this->jsonSuccess(array(
							'type'=>1,
							'prize'=> "明星底妆体验组合	1ml x 1ml",
							'number'=>5
						));
	}
	/**
	 * 抽奖
	 *	code 1 中奖
	 *	code 2 没中奖
	 *	code 3 重复抽奖限制
	 *	code 4 手机号码格式错误
	 *	code 5 地址门店不能为空
	 *	code 6 店铺不存在
	 *	code 7 地址不存在
	 *	code 8 session过期
	 */
	 
	public function actionLottery()
	{	
		//反馈参数
		$code = array(
			'lucky'=>1,
			'sad'=>2,
			'lock'=>3,
			'format'=>4,
			'empty'=>5,
			'noShop'=>6,
			'noCity'=>7,
			'timeout'=>8
		);
		//奖品类型
		$prizeType = array(
			'normal'=>0,
			'special'=>1,
			'bigGift'=>2
		);
		
		
		//抽奖途径
//		$fromArr = array(
//			'quick'=>0,
//			'all'=>1
//		);
		
		//$uid = Yii::app()->session['uid'];
//		//$uid = 5;
//		if (!isset($uid)) {
//			$this->jsonSuccess(array(
//				'type'=>$code['timeout']
//			));	
//		}
		
		$cityId = $_GET['cityId'];
		$marketId = $_GET['marketId'];
//		$from = $_GET['type'];
		$number = $_GET['phone'];
//		$specialOpen = false;
		
		//途径控制
//		if(($from != $fromArr['quick']) && ($from != $fromArr['all'])){
//			$this->jsonSuccess(array(
//				'type'=>$code['lock']
//			));	
//		}
		//验证城市、商铺信息是否为空
		if(!is_numeric($cityId) ||  !is_numeric($marketId) || 
		!isset($cityId) || !isset($marketId)){ 
			$this->jsonSuccess(array(
				'type'=>$code['empty']
			));	
		}
		//验证手机号码格式
		if(isset($number) && preg_match("/1[3458]{1}\d{9}$/",$number)){ 
			
			$model = Lottery::model()->findAllByAttributes(array(
				'phone'=>$number
			));
			//抽奖次数限制count($model) === 0
			if(count($model) === 0){
				$now = time();
				$currectTime = date("Y-m-d H:i:s",$now);
				$Market = Market::model()->findByPk($marketId);
				if(!$Market){
					$this->jsonSuccess(array(
						'type'=>$code['noShop']
					));	
				}
				$City = City::model()->findByPk($cityId);
				if(!$City){
					$this->jsonSuccess(array(
						'type'=>$code['noCity']
					));	
				}
				//奖品列表
				$prizeList = Prize::model()->findAll();
				
				$rightPrizeModel = array();
				//普通奖品时间判断
				foreach($prizeList as $key=>$val){  
					if ($val->isDel != 1) {
						  if($val->number < $val->count){
							  if($now < strtotime($val->endTime) && $now > strtotime($val->startTime) ){
								  $rightPrizeModel[] = $val;  
							  }
						  }
					}
				}
				$win = 0;
				//随机奖品Model
				$prizeCount = count($rightPrizeModel);
				if($prizeCount === 0){
					$result = SMessage::sendMs($number,"" ,"",2);
					$this->jsonSuccess(array(
						'type'=>$code['sad']
					));
				}
				if($prizeCount === 1){
					$correctPrizeModel = $rightPrizeModel[ 0 ];	
				}else{
					$prizeRand = rand(1, $prizeCount);
					$correctPrizeModel = $rightPrizeModel[ $prizeRand - 1 ];
				}
				$correctPrizeId = $correctPrizeModel->id;
				$correctPrizeType = $correctPrizeModel->type;
				if($correctPrizeModel->note){
					$prizeNoteStr = str_replace(' ','',$correctPrizeModel->note."".$correctPrizeModel->name);
				}else{
					$prizeNoteStr =  str_replace(' ','',$correctPrizeModel->name);
				}
				//抽奖记录参数
				$recordParamArr = array(
					"win"=>$win,
					"number"=>$number,
					"cityId"=>$cityId,
					"marketId"=>$marketId,
					"giftId"=>$correctPrizeId,
					"currectTime"=>$currectTime,
					"correctPrizeType"=>$correctPrizeType,
				);
				
				//奖品总数限制判断
				if($correctPrizeModel->number > $correctPrizeModel->count){
					//插入抽奖记录	
					$this->addLotteryRecord($recordParamArr);
					$result = SMessage::sendMs($number,"" ,"",2);
					$this->jsonSuccess(array(
						'type'=>$code['sad']
					));
				}
				//根据奖品Id，获取奖品中奖率
				$rate = $correctPrizeModel->rate;
				$currentNumber = 1;
				$denominator = floor(1/$rate);
				if($currentNumber === rand($currentNumber,$denominator)){
					$recordParamArr['win'] = 1;
				}else{
					$this->addLotteryRecord($recordParamArr);
					$result = SMessage::sendMs($number,"" ,"",2);
					$this->jsonSuccess(array(
						'type'=>$code['sad']
					));
				}
				$limitParamArr = array(
					'now'=>$now,
					'correctPrizeId'=>$correctPrizeId,
					'dayNumber'=>$correctPrizeModel->dayNumber,
					'hourNumber'=>$correctPrizeModel->hourNumber,
					'currectTime'=>$currectTime
				);
				if($this->limitInitAndControl($limitParamArr)){
					$correctPrizeModel->number += 1;
					$correctPrizeModel->updateTime = $currectTime;
					$correctPrizeModel->save();
					$this->addLotteryRecord($recordParamArr);
					
					//发送短信
					$shopNameGBK = iconv('UTF-8', 'GB2312',  str_replace(' ','',$Market->ShopName));
					$prizeNoteGBK = iconv('UTF-8', 'GB2312', $prizeNoteStr);
					
					$result = SMessage::sendMs($number,$shopNameGBK ,$prizeNoteGBK,1);
					
					$this->jsonSuccess(array(
						'type'=>$code['lucky'],
						'prize'=>$prizeNoteStr
					));
				}else{
					$recordParamArr['win'] = 0;
					$this->addLotteryRecord($recordParamArr);
					$result = SMessage::sendMs($number,"","",2);
					$this->jsonSuccess(array(
						'type'=>$code['sad']
					));
				}
				
			}else{
				$this->jsonSuccess(array(
						'type'=>$code['lock']
				));	
			}
		}else{
			$this->jsonSuccess(array(
				'type'=>$code['format']
			));	
		}
		
	}
	public function addLotteryRecord($param){
		$Lottery = new Lottery();
		$Lottery->phone = $param['number'];
		$Lottery->cityId = $param['cityId'];
		$Lottery->marketId = $param['marketId'];
		$Lottery->createTime = $param['currectTime'];
		$Lottery->updateTime = $param['currectTime'];
		$Lottery->giftId = $param['giftId'];
		$Lottery->win = $param['win'];
		$Lottery->save();
	}
	
	public function limitInitAndControl($param){
		$db = Yii::app()->db;
		//判断当天中奖数量限制
		$dayParamString = date("Y-m-d",$param["now"]); 
		$sqlStr = "SELECT daylimit.count,daylimit.id FROM daylimit where date_format(daylimit.dayTime,'%Y-%m-%d') = '".$dayParamString."' and daylimit.pid = '".$param["correctPrizeId"]."' ";
		$dayLimitArr = $db->createCommand($sqlStr)->queryrow(true);
		//判断小时中奖数量限制
		$hourParamString = date("Y-m-d H",$param["now"]); 
		$sqlStr = "SELECT hourlimit.count,hourlimit.id FROM hourlimit where date_format(hourlimit.hourTime,'%Y-%m-%d %H') = '".$hourParamString."' and hourlimit.pid = '".$param["correctPrizeId"]."' ";
		$hourLimitrArr = $db->createCommand($sqlStr)->queryrow(true);
		
		if(!is_array($dayLimitArr)){
			$Daylimit = new Daylimit();
			$Daylimit->pid = $param["correctPrizeId"];
			$Daylimit->dayTime = $param["currectTime"];
			$Daylimit->createTime = $param["currectTime"];
			$Daylimit->updateTime = $param["currectTime"];
			$Daylimit->count = 0;
			$Daylimit->save();
		}else{
			$Daylimit = Daylimit::model()->findByPk($dayLimitArr['id']);
		}
		if(!is_array($hourLimitrArr)){
			$Hourlimit = new Hourlimit();
			$Hourlimit->pid = $param["correctPrizeId"];
			$Hourlimit->hourTime =  $param["currectTime"];
			$Hourlimit->createTime =  $param["currectTime"];
			$Hourlimit->updateTime =  $param["currectTime"];
			$Hourlimit->count = 0;
			$Hourlimit->save();
		}else{
			$Hourlimit = Hourlimit::model()->findByPk($hourLimitrArr['id']);
		}
		
		$result = false;
		
		if($param["dayNumber"] != 0 && $param["hourNumber"] != 0){
			if(($Daylimit->count <  $param["dayNumber"]) && ($Hourlimit->count < $param["hourNumber"])){
				$result = true;
			}else{
				$result = false;
			}	
		}else{
			if($param["dayNumber"] == 0){ 
				if($param["hourNumber"] == 0){
					$result = true;
				}else{
					if($Hourlimit->count < $param["hourNumber"]){
						$result = true;
					}else{
						$result = false;
					}
				}
			}else{
				if($param["hourNumber"] == 0){
					if($Daylimit->count <  $param["dayNumber"]){
						$result = true;
					}else{
						$result = false;
					}
				}	
			}
		}
		if($result){
			$Daylimit->count = $Daylimit->count +1;
			$Daylimit->updateTime = $param["currectTime"];
			$Daylimit->save(); 
			$Hourlimit->count = $Hourlimit->count + 1;
			$Hourlimit->updateTime = $param["currectTime"];
			$Hourlimit->save(); 	
		}
		return $result;
	}
	/**
	 * 客户信息录入
	 */
	public function actionAddaddress()
	{	
		//$id = $this->uid;
		$id = 3;
		$code = 0;
		$cityId = $_GET['cityId'];
		$marketId = $_GET['marketId'];
		
		if(is_numeric($cityId) && is_numeric($marketId) && 
		isset($cityId) && isset($marketId)){ 
			$code = 1;
			$model = User::model()->findByPk($id);
			$model->cityId = $cityId;
			$model->marketId = $marketId;
			if($model->save()){
				$this->jsonSuccess(array(
					'code'=>$code
					));
			}
		}
		$this->jsonError(array(
			'code'=>$code
		),'参数错误');
	}
	/*
	*	商店列表数据录入
	*/
	public function actionAddShopInfo(){
		$cid = $_GET['CityID'];
		$City = City::model()->findByPk($cid);
		
		if(!$City){
			$city = new City();
			$city->CityID = $cid;
			$city->CityName = $_GET['CityName'];
			$city->save();
		}
		
		$sid = $_GET['ShopID'];
		$Market = Market::model()->findByPk($sid);
		
		if(!$Market){
			$Market = new Market();
			$Market->ShopID = $sid;
			$Market->CityID = $cid;
			$Market->CounterManager = $_GET['CounterManager'];
			$Market->DirectorName = $_GET['DirectorName'];
			$Market->ShopAddress = $_GET['ShopAddress'];
			$Market->ShopCode = $_GET['ShopCode'];
			$Market->ShopEmail = $_GET['ShopEmail'];
			$Market->ShopLocation_X = $_GET['ShopLocation_X'];
			$Market->ShopLocation_Y = $_GET['ShopLocation_Y'];
			$Market->ShopName = $_GET['ShopName'];
			$Market->ShopPhone = $_GET['ShopPhone'];
			$Market->save();
		}
		return $sid;
	}
}
