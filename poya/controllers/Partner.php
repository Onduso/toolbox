<?php
if (!defined('BASEPATH'))
	exit('No direct script access allowed');

include_once 'vendor/autoload.php';

define('LS_BASEURL', 'https://www.compassionkenya.com/surveys/index.php');
define('LS_USER', 'admin');
define('LS_PASSWORD', '@Compassion123');

/*
 *	@author 	: Nicodemus Karisa
 *	date		: 25 July, 2018
 *	Compassion International
 *	https://www.compassion-africa.org
 *	support@compassion-africa.org
 */

class Partner extends CI_Controller {
	
	private $sessionKey = null;
	private $rpcClient = null;
	
	function __construct() {
		parent::__construct();
		$this -> load -> database();
		$this -> load -> library('session');
		$this -> load -> library('finance');

		/*cache control*/
		$this -> output -> set_header('Cache-Control: no-store, no-cache, must-revalidate, post-check=0, pre-check=0');
		$this -> output -> set_header('Pragma: no-cache');

	}


	private function nomination_viable_projects($nomination_level) {
		
		$cluster_id = $this->session->cluster_id;
		
		$participants = $this->reorder_json_participants_from_data_file();
		
		$this -> db -> select(array('icpNo','email'));
		$this->db->where(array('email<>'=>""));
		
		//Make a method to control what to see depending on the level - Replace this code this that method call
		if ($nomination_level == 1) {
			$this -> db -> where(array('cluster_id' => $cluster_id));
			$this -> db -> where(array('icpNo<>'=>$this->session->center_id));
		}

		$projects = $this -> db -> get('projectsdetails') -> result_array();
		
		$fcp_id_array = array_column($projects, 'icpNo');
		$email_array = array_column($projects, 'email');
		
		$fcp_with_email = array_combine($fcp_id_array, $email_array);
		
		$update_participants = array();
		
		foreach($participants as $token=>$participant_email){
			if(in_array($participant_email, $fcp_with_email)){
				$update_participants[array_search($participant_email, $fcp_with_email)] = $token;
			}
		}

		return $update_participants;
	}
	
	
	private function create_write_data_file($data_files){
		foreach($data_files['data'] as $file=>$method){
			if(!file_exists(APPPATH.'data/'.$file.'.json')){
				file_put_contents(APPPATH."data/".$file.".json",$this->$method($data_files['survey_id']));
			}
		}
	}
	
	private function reorder_json_responses_from_data_file(){
		
		$responses_array = array();
		
		if(file_exists(APPPATH.'data/responses.json') && filesize(APPPATH.'data/responses.json') > 0){
			$responses = file_get_contents(APPPATH.'data/responses.json');
			$responses = json_decode($responses);
			
			$response_object = $responses->responses;
			
			$counter = 0;
			foreach($response_object as $response){
				foreach($response as $response_data){
					$responses_array[$response_data->token] = (array)$response_data;
				}
				$counter++;
			}
		}
		
		return $responses_array;
	}

	private function reorder_json_groups_from_data_file(){
		$groups_array = array();
		
		if(file_exists(APPPATH.'data/groups.json') && filesize(APPPATH.'data/groups.json') > 0){
				
			$groups = file_get_contents(APPPATH.'data/groups.json');
			$groups = json_decode($groups);
			
			foreach($groups as $group){
				$groups_array[$group->gid] = $group->group_name;
			}
		}	
		
		return $groups_array;
	}
	
	private function reorder_json_questions_from_data_file(){
		$questions_array = array();
		
		if(file_exists(APPPATH.'data/questions.json') && filesize(APPPATH.'data/questions.json') > 0){
				
			$questions = file_get_contents(APPPATH.'data/questions.json');
			$questions = json_decode($questions);
			
			foreach($questions as $question){
				$questions_array[$question->gid][$question->qid]['title'] = $question->title;
				$questions_array[$question->gid][$question->qid]['question'] = $question->question;
				$questions_array[$question->gid][$question->qid]['type'] = $question->type;
				$questions_array[$question->gid][$question->qid]['qid'] = $question->qid;
			}
		}	
		
		return $questions_array;
	}

	private function reorder_json_participants_from_data_file(){
		$participants_array = array();
		
		if(file_exists(APPPATH.'data/participants.json') && filesize(APPPATH.'data/participants.json') > 0){
				
			$participants = file_get_contents(APPPATH.'data/participants.json');
			$participants = json_decode($participants);
			
			foreach($participants as $participant){
				$participants_array[$participant->token] = $participant->participant_info->email;
			}
		}	
		
		return $participants_array;
	}

	private function get_vote_score($survey_id,$token,$nomination_level){
		
		$poya_vote_obj = $this->db->select(array('question_group_id','score'))->get_where('poya_vote',
		array('token'=>$token,'limesurvey_id'=>$survey_id,'nomination_level'=>$nomination_level));
		
		$group_by_group_id = array();
		
		if($poya_vote_obj->num_rows()>0){
			
			$poya_votes = $poya_vote_obj->result_object();
			
			foreach($poya_votes as $poya_vote){
				$group_by_group_id[$poya_vote->question_group_id] = $poya_vote->score;
			}
			
		}
		
		return $group_by_group_id;
	}
	
	private function survey_groups_with_questions($token, $nomination_level = 1){
		
		$grid_array = array();
		$survey_id = $this->active_for_voting_survey_id();
		$votes = $this->get_vote_score($survey_id, $token, $nomination_level);
		
		$groups = $this->reorder_json_groups_from_data_file();
		$questions = $this->reorder_json_questions_from_data_file();
		$responses = $this->reorder_json_responses_from_data_file();
		
		foreach($groups as $group_key => $group_name){			
			foreach($questions[$group_key] as $question){
				if(
					($responses[$token][$question['title']] !== "" && $question['type'] !== "U") || 
					(strlen($responses[$token][$question['title']]) > 250 && $question['type'] == "U")
				){
					$grid_array[$group_key]['group_name'] = $group_name;
					
					if($question['type'] == "|"){
						$uploads = array();
						$response = json_decode($responses[$token][$question['title']]);
						
						foreach($response as $file_key=>$uploaded_file){
							$grid_array[$group_key]['questions'][$question['title']]['response'][urldecode($uploaded_file->name)] = LS_BASEURL."/admin/responses?sa=actionDownloadfile&surveyid=".$survey_id."&iResponseId=".$responses[$token]['id']."&iQID=".$question['qid']."&iIndex=".$file_key;
						}
						
						
					}else{
						$grid_array[$group_key]['questions'][$question['title']]['response'] = $responses[$token][$question['title']];
					}
					
					$grid_array[$group_key]['questions'][$question['title']]['question_text'] = $question['question'];
					$grid_array[$group_key]['questions'][$question['title']]['question_type'] = $question['type'];
					$grid_array[$group_key]['questions'][$question['title']]['question_id'] = $question['qid'];
					$grid_array[$group_key]['score'] = isset($votes[$group_key])?$votes[$group_key]:0;
				}
			
			}
		}
		
		return $grid_array;
	}

	function active_for_voting_survey_id(){
		$survey_id = 0;
		
		$surveys = $this->db->get_where('poya_survey',array('status'=>1));
		
		if($surveys->num_rows()>0){
			$survey_id = $surveys->row()->limesurvey_id;
		}
		
		return $survey_id;
	}

	function dashboard() {
		if ($this -> session -> userdata('admin_login') != 1)
			redirect(base_url(), 'refresh');
		
		//Set the survey_id
		$survey_id = $this->active_for_voting_survey_id();
		
		//Write data json		
		$data_files['survey_id'] = $survey_id;
		$data_files['data']['responses'] = 'export_responses';
		$data_files['data']['groups']='list_groups';
		$data_files['data']['questions']='list_questions';
		$data_files['data']['participants']='list_participants';
		
		$this->create_write_data_file($data_files);

		//Prepare page_data array for view output
		
		$page_data['projects'] = array_chunk($this -> nomination_viable_projects(1), 15,true);
		$page_data['page_name'] = 'dashboard';
		$page_data['page_title'] = get_phrase('nominate');
		$this -> load -> view('backend/index', $page_data);
	}
	
	private function get_rpc_client(){
		// instantiate a new client
		$this->rpcClient = new \org\jsonrpcphp\JsonRPCClient(LS_BASEURL . '/admin/remotecontrol');
		
		return $this->rpcClient;
	}
	
	private function get_limesurvey_session(){
		$this->get_rpc_client();

		// receive session key
		$this->sessionKey = $this->rpcClient -> get_session_key(LS_USER, LS_PASSWORD);
		
		return $this->sessionKey;
	}
	
	private function rpc_client_session_instantiate(){
		header('Content-Type: text/html; charset=utf-8');		
		
		//Instatiate rpc client
		$this->get_rpc_client();
		
		//Instatiate lime session key
		$this->get_limesurvey_session();
		
	}

	private function export_responses($survey_id) {
		
		$this->rpc_client_session_instantiate();
		
		// receive surveys responses of the given survey id
		$aResult = $this->rpcClient->export_responses($this->sessionKey, $survey_id, 'json', null, 'complete', 'code', 'long', null, null);
		
		// release the session key
		$this->rpcClient -> release_session_key($this->sessionKey);
		
		//Decode base64 string to a json object
		$answers_decoded = base64_decode($aResult);
		
		return $answers_decoded;
	}
	
	private function list_groups($survey_id){
		
		$this->rpc_client_session_instantiate();
		
		$aResult = $this->rpcClient->list_groups($this->sessionKey, $survey_id);
		
		// release the session key
		$this->rpcClient -> release_session_key($this->sessionKey);
		
		return json_encode($aResult);
	}
	
	private function list_questions($survey_id){
		
		$this->rpc_client_session_instantiate();
		
		$aResult = $this->rpcClient->list_questions($this->sessionKey, $survey_id);
		
		// release the session key
		$this->rpcClient -> release_session_key($this->sessionKey);
		
		return json_encode($aResult);
		
	}
	
	private function list_participants($survey_id){
		$this->rpc_client_session_instantiate();
		
		$aResult = $this->rpcClient->list_participants($this->sessionKey, $survey_id,0,1000,false, false);
		
		// release the session key
		$this->rpcClient -> release_session_key($this->sessionKey);
		
		return json_encode($aResult);
	}
	
	/**Ajax call methods**/
	
	function post_a_score(){
		$post_data = $this->input->post();
		
		$data['nomination_level'] = $post_data['nominationLevel'];
		$data['limesurvey_id'] = $post_data['surveyId'];
		$data['question_group_id'] = $post_data['questionGroupId'];
		$data['token'] = $post_data['token'];
		$data['fcp_id'] = $post_data['fcp'];
		$data['voting_user_id'] = $this->session->login_user_id;
		
		//Check if vote exists
		$this->db->where($data);
		$poya_votes = $this->db->get('poya_vote'); 
		
		$msg = "";
		
		if($poya_votes->num_rows() == 0){
			$data['score'] = $post_data['score'];
			$this->db->insert('poya_vote',$data);
			$msg = "Insert";
		}else{
			$data['score'] = $post_data['score'];
			$poya_vote_id = $poya_votes->row()->poya_vote_id;
			$this->db->where(array('poya_vote_id'=>$poya_vote_id));
			$this->db->update('poya_vote',$data);
			$msg = "Update";
		}
		
		// if($this->db->affected_rows()>0){
			// echo "Successful ".$msg;
		// }else{
			// echo "Failed ".$msg;
		// }
		echo $this->retrieve_profiles($post_data['token']);
	}
	
	private function get_vote_cast($voting_user,$nomination_level,$fcp_id){
		
		$user_votes = $this->db->get_where('poya_vote',
		array('voting_user_id'=>$voting_user,'nomination_level'=>$nomination_level));
		
		if($user_votes->num_rows()>0){
			return $user_votes->result_object();
		}else{
			return array();
		}
	}
	

	function retrieve_profiles($token) {
		$data['grid'] = $this->survey_groups_with_questions($token);
		$data['survey_id'] = $this->active_for_voting_survey_id();
		$data['nomination_level'] = 1;
		$data['token'] = $token;
		$data['fcp'] = $this->input->post('fcp');
		$data['question_groups'] = $this->reorder_json_groups_from_data_file();
		$data['nomination_level'] = array('1'=>'Cluster Level','2'=>'Regional Level','3'=>'National Level');
		
		$voting_user = $this->session->login_user_id;
		$nomination_level = 1;
		
		$data['votes_cast'] = $this->get_vote_cast($voting_user,$nomination_level,$this->input->post('fcp'));
		
		echo $this -> load -> view('backend/loaded/profiles.php', $data, true);
	}

}
