<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class PaymentController extends Controller
{
    
    //get the amount applicable to the user
    protected function getFees($user,$course_id){ //TODO change the logic to return the exact amt to be paid. if error then return -1

        $now = new DateTime();

        $c_payment_dtls=CoursePaymentDetails::where('c_id',$course_id)->first();
        if($c_payment_dtls == null || $user->pay_category == NULL)
             return -1;

        
        $fee=0;
       
       //student
       if($user->pay_category == 1)
        {    //check if discount is applicable
            if( $c_payment_dtls->ebd != NULL && $now <= new DateTime($c_payment_dtls->discount_end_date))
                $fee = $c_payment_dtls->price -round(((float)$c_payment_dtls->price *((float)$c_payment_dtls->ebd/100)));
            else
                $fee = $c_payment_dtls->price - round(((float)$c_payment_dtls->price *((float)$c_payment_dtls->student_disc/100)));
        }
        else //non-student
        {
            //check if discount is applicable
            if( $c_payment_dtls->non_student_ebd != NULL && $now <= new DateTime($c_payment_dtls->discount_end_date))
                $fee = $c_payment_dtls->price -round(((float)$c_payment_dtls->price *((float)$c_payment_dtls->non_student_ebd/100)));
            else
                $fee = $c_payment_dtls->price - round(((float)$c_payment_dtls->price *((float)$c_payment_dtls->non_student_disc/100)));
        }
        Log::info('Fee:'.$fee);
        // $fee=1;
        return $fee;

    }


    protected function getPaymentInfo($course_id){

        $user=TeamMemberDetails::where("login_id",Auth::user()->id)->first();//TODO get the user obj

        $fee=$this->getFees($user,$course_id);//TODO change function call parameters

        if($fee == -1)
         return redirect()->route('home');
        
        //check if user has paid before once
        $payment = Payment::where(['user_id'=>Auth::user()->id])->first();
 
        
    	//if no payments are done before
        if( $payment==NULL ){
            return view('payment_details',[
                'fee'=>$fee,
                'enable_button'=>true,
                'status'=>null,
            ]);
        }
        else //if payment is done at least once
        {
            if($payment->status == 'S'  || $payment->reconciled == 1){
          
                $trans_date=$payment->trans_date;
                if($payment->trans_date == null )
                    $trans_date=$payment->recon_date;

                return view('payment_details',[
                    'fee'=>$payment->amount,
                    'enable_button'=>false,
                    'status'=>'success',
                    'trans_id'=> $payment->trans_id,
                    'trans_date'=>$trans_date,
                    'message'=>$payment->remark,
                ]);
                
            }
            if($payment->status == null ){
                return view('payment_details',[
                    'fee'=>$fee,
                    'enable_button'=>true,
                    'status'=>null
                ]);
            }
            
            if($payment->status == 'F' ){
                return view('payment_details',[
                    'fee'=>$payment->amount,
                    'enable_button'=>true,
                    'status'=>'fail',
                    'trans_id'=> $payment->trans_id,
                    'trans_date'=>$payment->trans_date,
                    'message'=>$payment->remark,
                    'course_id'=>$course_id
                ]);
            }
            if($payment->status == 'X'){
                return view('payment_details',[
                    'fee'=>$payment->amount,
                    'enable_button'=>false,
                    'status'=>'X',
                    'trans_id'=> $payment->trans_id,
                    'trans_date'=>$payment->trans_date,
                    'message'=>'Not available',
                    'course_id'=>$course_id

                ]);
            }
            
        }
        
    }


    public function makePayment(Request $request){
        $user=TeamMemberDetails::where("login_id",Auth::user()->id)->first();
       
        $fee=$this->getFees($user,$request->course_id);//get the amount to be paid
        $payment = Payment::where(['c_id'=>$request->course_id,'user_id'=>$user->login_id])->first();
        
        

        //if payment exists then check if the payment was successful.
        if( $payment!= NULL && $payment->status == 'S'){
            return view('payment_details',[
                'fee'=>$fee,
                'enable_button'=>false,
                'status'=>'success',
            ]);
        }
      
        //get server configuration
        $server = ServerConf::first();

        //header
        $client = new Client([
            'Content-Type' => 'application/json',
            'base_uri' => $server->base_uri,
            'headers' => [
            'Authorization' => 'Bearer '.$server->token,
            ]
        ]);
       
        $num_of_req=0;
        while($num_of_req < 3)
        {
            $num_of_req++;
        
            if($payment==NULL)//if the user has never attempted to pay then create user and save details in payment table
            {
                
                $response =null;
                //create user at payment gateway
                try{
                    Log::info('make user create request');
                    Log::info($user);
                    $response = $client->post('users', [
                        'json' => [
                            'clientUserId' => $user->login_id,
                            'name' => $user->name,
                            'emailId' => $user->email,
                        ]
                    ])->getBody();
                    
                }
                catch(ClientException $e) //exception
                {
                    //check status code
                    Log::info("Exception while creating user:".$user->login_id);
                    Log::info("StatusCode:".$e->getResponse()->getStatusCode());
                    Log::info($response);
                    if($e->getResponse()->getStatusCode() == 401)//unauthorized! token expired
                    {
                        Log::info('Token Expired!! while requesting for user_id: '.$user->login_id);
                        Log::info($e->getResponse()->getBody()->getContents());

                        //create another client without token in header
                        $client= new Client([
                            'Content-Type' => 'application/json',
                            'base_uri' => $server->base_uri
                        ]);

                        //make a request to get new token
                        $token_response = $client->get('auth/client/refresh-token',  [
                            'query' => [
                                'token' => $server->refresh_token,
                            ]
                        ])->getBody();

                        $token_data = json_decode($token_response->getContents());
                        $server->token=$token_data->authToken;
                        $server->refresh_token=$token_data->refreshToken;
                        $server->save();

                        //update the client with new token
                        $client= new Client([
                            'Content-Type' => 'application/json',
                            'base_uri' => $server->base_uri,
                            'headers' => [
                                'Authorization' => 'Bearer '.$server->token,
                                ]
                        ]);

                        continue;
                        // return response()->json(['error'=> 'The request could not be completed. Please contact us at resources@e-yantra.org'],401);
                    }
                    if($e->getResponse()->getStatusCode() == 409) //user already exists
                    {
                        Log::info('user already exists: '.$user->login_id);
                        Log::info($e->getResponse()->getBody()->getContents());
                        

                        //to get the user details
                        $response = $client->get('users/find',  [
                            'query' => [
                                'clientUserId' => $user->login_id,
                            ]
                        ])->getBody();
                        //return response()->json(['error'=> 'If you have tried the payment, wait for 24 hours for the payment status.'],400);
                        
                    }
                    else{
                        Log::info($e->getResponse()->getBody()->getContents());
                        return response()->json(['error'=> 'Ooops! Something went wrong. Please try again after sometime. If the problem still persists contact us at resources@e-yantra.org. '],400);
                    }
                }
                
                $data = json_decode($response->getContents());

            
                //create entry for the user in payment table
                $payment = new Payment();
                $payment->user_id= $user->login_id;
                $payment->c_id= $request->course_id;
                $payment->payer_user_id = $data->id;
                $payment->amount = $fee;
                $payment->currency= 'INR';
                $payment->purpose='mooc_course_'.$request->course_id;
                $payment->save();
            }//if ends
    
            //updating fees
            $payment->amount=$fee;
            $payment->save();

            //get the url for payment
            Log::info('make url create request');
            $response=null;
            try{
                $response = $client->post('payment/url', [
                    'json' => [
                        'userId' => $payment->payer_user_id,
                        'amountDue' => (float)$payment->amount,  
                        // 'amountDue' => 1,
                        'purpose' => 'mooc_course_'.$request->course_id,
                        'currency' => 'INR',
                    ]
                ])->getBody();
            
            } 
            catch(ClientException $e) 
            {
                //check status code
                Log::info("Making payment for user id:".$user->login_id);
                Log::info("StatusCode:".$e->getResponse()->getStatusCode());
                Log::info($e->getResponse()->getBody()->getContents());

                if($e->getResponse()->getStatusCode() == 401)//unauthorized! token expired
                {
                    Log::info('Token Expired!! while requesting for user_id: '.$user->login_id);
                    Log::info($e->getResponse()->getBody()->getContents());


                    //create another client without token in header
                    $client= new Client([
                        'Content-Type' => 'application/json',
                        'base_uri' => $server->base_uri
                    ]);

                    //make a request to get new token
                    $token_response = $client->get('auth/client/refresh-token',  [
                        'query' => [
                            'token' => $server->refresh_token,
                        ]
                    ])->getBody();
                    
                    $token_data = json_decode($token_response->getContents());
                    $server->token=$token_data->authToken;
                    $server->refresh_token=$token_data->refreshToken;
                    $server->save();


                    //update the client with new token
                    $client= new Client([
                        'Content-Type' => 'application/json',
                        'base_uri' => $server->base_uri,
                        'headers' => [
                            'Authorization' => 'Bearer '.$server->token,
                            ]
                    ]);
                    continue;
                    
                    // return response()->json(['error'=> 'The request could not be completed. Please contact us at resources@e-yantra.org'],401);
                }
                //if already paid 
                if($e->getResponse()->getStatusCode() == 409){
                    $response = $client->get('payment/successful/user/'.$payment->payer_user_id)->getBody();
                    $data = json_decode($response->getContents());
                
                    Log::info('already paid');

                    if( $payment->status == null  || $payment->status == 'F' || $payment->status == 'X'){
                        
                        //update the table payment
                        $payment->status =   $data[0]->status;
                        $payment->trans_id = $data[0]->transId;
                        $payment->ref_no =  $data[0]->refNo;
                        $payment->trans_date =new  DateTime($data[0]->transDateTime);
                        $payment->remark = $data[0]->msg;
                        $payment->req_id = $data[0]->reqId;
                        $payment->save();
                    }
                    return response()->json(['We have recorded your payment.This section will reflect the status in 2-3 days.']);
                    

                
                    
                }     
            }
            $data = json_decode($response, true);
     
            //getting request id
            $querystring=(parse_url($data['url'], PHP_URL_QUERY));
            $parameters=urldecode(explode('=', $querystring)[1]);
            parse_str($parameters, $param_array);
            
            //saving request id
            $payment->req_id = $param_array['sReqId'];
            $payment->save();

            //Log::info("User: ".$user->login_id.":->".$data['url']);
            return redirect($data['url']);
        }
        
     
    }



    //handle immdeiate response from server
    public function paymentImmediateResponse(Request $request){
        $query = urldecode($request->server->get('QUERY_STRING'));
        $data = array();
       
        //create array
        foreach (explode('&', $query) as $chunk) {
            $param = explode("=", $chunk);
            if ($param) {
                if($param[0] == 'requestType'){
                    $data['requestType'] = $param[1];
                }
                if($param[0] == 'reqId'){
                    $data['reqId'] = $param[1];
                }
                if($param[0] == 'userId'){
                    $data['userId'] = $param[1];
                }
                if($param[0] == 'totalAmt'){
                    $data['totalAmt'] = $param[1];
                }
                if($param[0] == 'refNo'){
                    $data['refNo'] = $param[1];
                }
                if($param[0] == 'status'){
                    $data['status'] = $param[1];
                }
                if($param[0] == 'transId'){
                    $data['transId'] = $param[1];
                }
                if($param[0] == 'transDateTime'){
                    $data['transDateTime'] = $param[1];
                }
                if($param[0] == 'provId'){
                    $data['provId'] = $param[1];
                }
                if($param[0] == 'msg'){
                    $data['msg'] = $param[1];
                }
            }
        }
        $payment = Payment::where(['payer_user_id' => $data['userId'],'req_id'=>$data['reqId']])->first();

        if($payment){
            //update payment details
            $payment->status =   $data['status'];
            $payment->trans_id = $data['transId'];
            $payment->ref_no =  $data['refNo'];
            $payment->amount=$data['totalAmt'];
            $payment->trans_date = new  DateTime($data['transDateTime']);
            $payment->remark = $data['msg'];
            $payment->req_id = $data['reqId'];
            $payment->prov_id=$data['provId'];
            $payment->save();

            
            $user=TeamMemberDetails::where('login_id',$payment->user_id)->first();
            if($payment->status == 'S'){
                //insert course mapping 
                $teamcoursemap=TeamCourseMap::where(['team_id'=>$payment->user_id,'c_id'=>$payment->c_id])->first();
                if($teamcoursemap == null)
                    TeamCourseMap::create(['team_id'=>$payment->user_id,'c_id'=>$payment->c_id,'not_paid'=>0]);
                else
                {
                    $teamcoursemap->not_paid=0;
                    $teamcoursemap->save();
                }    

            }
            
            //send email
            Mail::to($user->email)
            ->bcc('master@e-yantra.org')
            ->queue(new PaymentEmail($payment,$user->name));

            //redirect to payment page
            return redirect()->route('paymentpage',['course_id' => $payment->c_id]);
       
        }
        
       
    }


    //this function reconciles the transaction.
    public function reconcile(Request $request){
        Log::info('inside recon');
        $query = urldecode($request->server->get('QUERY_STRING'));
        $data = array();
       
        //create array
        foreach (explode('&', $query) as $chunk) {
            $param = explode("=", $chunk);
            if ($param) {
                if($param[0] == 'requestType'){
                    $data['requestType'] = $param[1];
                }
                if($param[0] == 'reqId'){
                    $data['reqId'] = $param[1];
                }
                if($param[0] == 'userId'){
                    $data['userId'] = $param[1];
                }
                if($param[0] == 'totalAmt'){
                    $data['totalAmt'] = $param[1];
                }
                if($param[0] == 'refNo'){
                    $data['refNo'] = $param[1];
                }
                if($param[0] == 'transId'){
                    $data['transId'] = $param[1];
                }
                if($param[0] == 'reconDateTime'){
                    $data['reconDateTime'] = $param[1];
                }
                if($param[0] == 'provId'){
                    $data['provId'] = $param[1];
                }
                if($param[0] == 'msg'){
                    $data['msg'] = $param[1];
                }
            }
        }
        Log::info('USER iS: '.$data['userId']);
        $payment = Payment::where(['payer_user_id' => $data['userId'],'req_id'=>$data['reqId']])->first();


        if($payment){
           //if previously the transaction had failed or is undefined
            if($payment->status == null || $payment->status == "F" || $payment->status == "X") 
            {   //update payment details
                $payment->trans_id = $data['transId'];
                $payment->amount=$data['totalAmt'];
                $payment->ref_no =  $data['refNo'];
                $payment->req_id = $data['reqId'];
                $payment->remark = "Transaction Successful";
                $payment->recon_date=$data['reconDateTime'];
                $payment->prov_id=$data['provId'];
                $payment->reconciled =1 ;
                $payment->save();

                
                $user=TeamMemberDetails::where('login_id',$payment->user_id)->first();

                $teamcoursemap=TeamCourseMap::where(['team_id'=>$payment->user_id,'c_id'=>$payment->c_id])->first();
                if($teamcoursemap == null)
                    //insert course mapping 
                    TeamCourseMap::create(['team_id'=>$payment->user_id,'c_id'=>$payment->c_id,'not_paid'=>0]);
                else
                {
                    $teamcoursemap->not_paid=0;
                    $teamcoursemap->save();
                }  


                //send email
                Mail::to($user->email)
                ->bcc('master@e-yantra.org')
                ->queue(new PaymentEmail($payment,$user->name));
            
            }
            else{
                $payment->reconciled =1;
                $payment->recon_date=$data['reconDateTime'];
                $payment->prov_id=$data['provId'];
                $payment->save();
            }
        }
    
    }


    //make a request for immediate response for a user
    public function immediateResponseForUser($course_id,$user_id){

     
        $payment = Payment::where(['payer_user_id'=>$user_id,'c_id'=>$course_id])->first();
      

        if($payment == NULL)
        {
            return response()->json(['msg'=>'No payment record in the table'],200);
        }
        if($payment->status == 'S')
        {
            return response()->json(['msg'=>'The transaction was successful already!!'],200);
        }
        else{
                //get server configuration
                $server = ServerConf::first();
               
                //header
                $client = new Client([
                    'Content-Type' => 'application/json',
                    'base_uri' => $server->base_uri,
                    'headers' => [
                    'Authorization' => 'Bearer '.$server->token,
                    ]
                ]);
                
                try{
                    //get the immediate response for user
                    $response = $client->get('payment/successful/user/'.$payment->payer_user_id)->getBody();
                    $data = json_decode($response->getContents());

                    if($data == null || count($data) == 0)
                     return response()->json(['msg'=>'NO IR response available' ],200);

                    $pay_data=null;
                   
                    foreach($data as $single_obj ){

                        if($single_obj->reqId == $payment->req_id )
                        {
                            $pay_data=$single_obj;
                            break;
                        }
                    }
                    if($pay_data==null)
                    {
                        Log::info("Error while requesting immediate response for user id: ".$payment->payer_user_id);
                        Log::info('Couldnt find the matching reqId');
                        return response()->json(['error'=>'Request ID not found. Check logs.'], 400);
                    }
                    
                    
                    if( $payment->status == null  || $payment->status == 'F' || $payment->status == 'X'){
                        Log::info($pay_data->status);
                        //update the table payment
                        $payment->status =   $pay_data->status;
                        $payment->trans_id = $pay_data->transId;
                        $payment->ref_no =  $pay_data->refNo;
                        $payment->trans_date = new  DateTime($pay_data->transDateTime);
                        $payment->remark = $pay_data->msg;
                        $payment->req_id = $pay_data->reqId;
                        $payment->save();
                    }
                    if($payment->status == 'S'){
                        $user=TeamMemberDetails::where('login_id',$payment->user_id)->first();
                      
          
                        //insert course mapping 
                        $teamcoursemap=TeamCourseMap::where(['team_id'=>$payment->user_id,'c_id'=>$payment->c_id])->first();
                        if($teamcoursemap == null)
                            TeamCourseMap::create(['team_id'=>$payment->user_id,'c_id'=>$payment->c_id,'not_paid'=>0]);
                        else
                        {
                            $teamcoursemap->not_paid=0;
                            $teamcoursemap->save();
                        }    

                        Log::info('Sending mail');
                        //send email
                        Mail::to($user->email)
                        ->bcc('master@e-yantra.org')
                        ->queue(new PaymentEmail($payment,$user->name));
                    }
                    return response()->json(['msg'=>'The immediate response saved!!'],200);
                }
                catch(ClientException $e) 
                {
                    //check status code
                    Log::info("Error while requesting immediate response for user id: ".$payment->payer_user_id);
                    Log::info("StatusCode:".$e->getResponse()->getStatusCode());
                    Log::info($e->getResponse()->getBody()->getContents()); 

                    return response()->json(['error'=>'Some Error occurred. Check logs. Status code: '.$e->getResponse()->getStatusCode()], 400);
                }
        }


    }

    //make a request for recon response for a user
    public function reconciliationForUser($course_id,$user_id){
        
       
        $payment = Payment::where(['payer_user_id'=>$user_id,'c_id'=>$course_id])->first();
       

        if($payment == NULL)
        {
            return response()->json(['msg'=>'No payment record in the table'],200);
        }
        else{
                //get server configuration
                $server = ServerConf::first();

                //header
                $client = new Client([
                    'Content-Type' => 'application/json',
                    'base_uri' => $server->base_uri,
                    'headers' => [
                    'Authorization' => 'Bearer '.$server->token,
                    ]
                ]);

                Log::info($server->base_uri);
                try{
                    //get the immediate response for user
                    $response = $client->get('payment/reconciled/user/'.$payment->payer_user_id)->getBody();
                    $data = json_decode($response->getContents());

                    if($data == null || count($data) == 0)
                        return response()->json(['msg'=>'NO recon response available' ],200);

                    $pay_data=null;
                   
                    //if there are multiple payments match the transaction with reqid
                    foreach($data as $single_obj ){

                        if($single_obj->reqId == $payment->req_id )
                        {
                            $pay_data=$single_obj;
                            break;
                        }
                    }
                    if($pay_data==null)
                    {
                        Log::info("Error while requesting immediate response for user id: ".$payment->payer_user_id);
                        Log::info('Couldnt find the matching reqId');
                        return response()->json(['error'=>'Request ID not found. Check logs.'], 400);
                    }    
                   
                    if($payment->status == null || $payment->status == "F" || $payment->status == "X") 
                    {    //update the table payment
                        $payment->trans_id = $pay_data->transId;
                        $payment->amount=$pay_data->totalAmt;
                        $payment->ref_no =  $pay_data->refNo;
                        $payment->req_id = $pay_data->reqId;
                        $payment->remark = "Transaction Successful";
                        $payment->recon_date=new DateTime($pay_data->reconDateTime);
                        $payment->prov_id=$pay_data->provId;
                        $payment->reconciled =1 ;
                        $payment->save();

                        $user=TeamMemberDetails::where('login_id',$payment->user_id)->first();
                        //insert course mapping 
                        $teamcoursemap=TeamCourseMap::where(['team_id'=>$payment->user_id,'c_id'=>$payment->c_id])->first();
                        if($teamcoursemap == null)
                            TeamCourseMap::create(['team_id'=>$payment->user_id,'c_id'=>$payment->c_id,'not_paid'=>0]);
                        else
                        {
                            $teamcoursemap->not_paid=0;
                            $teamcoursemap->save();
                        }   
                        //send email
                        Mail::to($user->email)
                        ->bcc('master@e-yantra.org')
                        ->queue(new PaymentEmail($payment,$user->name));
                       
                    }   
                    else{
                        $payment->reconciled =1;
                        $payment->recon_date=$data[0]->reconDateTime;
                        $payment->prov_id=$data[0]->provId;
                        $payment->save();
                    }
                    

                     return response()->json(['msg'=>'The recon response saved!!'],200);
                }
                catch(ClientException $e) 
                {
                    //check status code
                    Log::info("Error while requesting recon response for user id: ".$payment->payer_user_id);
                    Log::info("StatusCode:".$e->getResponse()->getStatusCode());
                    Log::info($e->getResponse()->getBody()->getContents()); 

                    return response()->json(['error'=>'Some Error occurred. Check logs. Status code: '.$e->getResponse()->getStatusCode()], 400);
                }
        }


    }



}