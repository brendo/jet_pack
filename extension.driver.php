<?php

	include_once(EXTENSIONS . '/jet_pack/lib/class.rule.php');
	require_once(EXTENSIONS . '/email_template_manager/lib/class.emailtemplatemanager.php');

Class extension_jet_pack extends Extension {

	// About this extension:
	public function about()
	{
		return array(
			'name' => 'Jet Pack',
			'version' => '1.0',
			'release-date' => '2011-10-26',
			'author' => array(
				'name' => 'Chay Palmer',
				'website' => 'http://www.randb.com.au',
				'email' => 'chay@randb.com.au'),
			'description' => 'Configure email alerts for content creation for author roles'
		);
	}
	
	// Set the delegates:
	public function getSubscribedDelegates()
	{
		return array(
			array(
				'page' => '/backend/',
				'delegate' => 'ExtensionsAddToNavigation',
				'callback' => 'fetchNavigation'
			),
			array(
				'page' => '/publish/new/',
				'delegate' => 'EntryPostCreate',
				'callback' => 'checkForRules'
			)
		);
	}
	
	public function fetchNavigation(){
		return array(
			array(
				'location' 	=> __('System'),
				'name' 		=> __('Jet Pack Rules'),
				'link' 		=> '/rules/'
			)
		);
	}
	
	public function install(){
		Administration::instance()->saveConfig();
	
		return Symphony::Database()->import("
			DROP TABLE IF EXISTS `tbl_jet_pack_rules`;
			CREATE TABLE `tbl_jet_pack_rules` (
			  `id` int(11) unsigned NOT NULL auto_increment,
			  `section` int(11) NOT NULL,
			  `role-1` int(11) NOT NULL,
			  `role-2` int(11) NOT NULL,
			  `template` varchar(50) NOT NULL,
			  PRIMARY KEY  (`id`)
			) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
		");
	}
	
	public function uninstall(){
	
		return Symphony::Database()->query("
			DROP TABLE IF EXISTS
				`tbl_jet_pack_rules`;
		");
	}
	
	public static function baseURL(){
		return SYMPHONY_URL . '/extension/jet_pack/';
	}
	
	public function checkForRules($context)
	{
		$entry = $context['entry']->get('id');
		$author = Administration::instance()->Author->get('id');
		$author_roles = Symphony::ExtensionManager()->create('author_roles');
		$section = $context['section']->get('id');
		
		$id_role   = $author_roles->getAuthorRole($author);
		
		$rules = Symphony::Database()->fetch(sprintf("
				SELECT * FROM `tbl_jet_pack_rules` WHERE `section` = %d AND `role-1` = %d ORDER BY `id` ASC LIMIT 1",
				$section,
				$id_role
				
			));

		if(empty($rules)){
			return true;
		}else{
			$this->applyRule($rules[0]['id'], $author,$entry,$context['section']->get('handle'));
		}
		
	}
	
	public function applyRule($rule_id,$author,$entry,$section){
	
		$rule = RuleManager::fetch($rule_id);
		
		
		$template = EmailTemplateManager::load($rule->get('template'));
		$template->parseProperties();
		$recipients = $this->getRecipients($rule->get('role-2'));
		
		$template->{'recipients'} = $emails;
		$template->parseProperties();
		$output = $template->render();
		
		$author = $this->getAuthor($author);
		$entry_url = SYMPHONY_URL . '/publish/' . $section . '/edit/' . $entry . '/';
		
		$entry_html_url = '<a href="'. $entry_url .'"> View Entry </a>';
		
	
		$search  = array('{$jet-pack-user}', '{$jet-pack-section}', '{$jet-pack-link}');
		$replace = array($author, $section, $entry_url);

		$text_email = str_replace($search, $replace, $output['plain']);
		
		$replace = array($author, $section, $entry_html_url);
		$html_email = str_replace($search, $replace, $output['html']);
		
		$email['content']['html'] = $html_email;
		$email['content']['plain'] = $text_email;
		$email['subject'] = $template->subject;
		$email['reply_to_name'] = $template->reply_to_name;
		$email['reply_to_email_address'] = $template->reply_to_email_address;
		
		
		
		$this->send($email,$recipients);
	
	}
	
	public function getAuthor($id){
	
		$author = Symphony::Database()->fetch('
			SELECT
				`first_name`,
				`last_name`
			FROM
				`tbl_authors`
			WHERE
				`id` = ' . $id . ';');
			
		return $author[0]['first_name'] . ' ' . $author[0]['last_name'];
	}
	
	public function getRecipients($role){
	
		$authors = Symphony::Database()->fetch('
			SELECT
				A.`id`,
				A.`first_name`,
				A.`last_name`,
				A.`email`
			FROM
				`tbl_authors` A,
				`tbl_author_roles_authors` B
			WHERE
				B.`id_role` = '.$role.' AND
				B.`id_author` = A.`id`;');
		
		$recipients = array();
		foreach($authors as $author){
			$name = $author['first_name'] . ' ' . $author['last_name'];
			$recipients[$name] = $author['email'];
		}
		return $recipients;		
	}
	
	public function send($msg, $recipients){
	
		$email = Email::create();  
				
		try{
		    $email->recipients = $recipients;
		    
		    $email->subject                = $msg['subject'];
		    $email->text_plain             = $msg['content']['plain'];
		    $email->text_html              = $msg['content']['html'];
		
		    // Optional: overwrite default sender
		    $email->sender_name            = $msg['reply_to_name'];
		    $email->sender_email_address   = $msg['reply_to_email_address'];
		
		    // Optional: set a different text encoding (default is 'quoted-printable')
		    $email->text_encoding          = 'base64';
 		
	
		    
		    
		    return $email->send();
		    
		}
		catch(EmailGatewayException $e){
		    throw new SymphonyErrorPage('Error sending email. ' . $e->getMessage());
		}
		catch(EmailException $e){
		    throw new SymphonyErrorPage('Error sending email. ' . $e->getMessage());
		}	
	}

}