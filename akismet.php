<?php
if (!defined('FORUM_ROOT'))
    define('FORUM_ROOT', '../../');

require FORUM_ROOT.'include/common.php';
require FORUM_ROOT.'include/common_admin.php';

$ext_info['path']=FORUM_ROOT.'/extensions/akismet';

if (!$forum_user['is_admmod'])
    message($lang_common['No permission']);

require FORUM_ROOT.'lang/'.$forum_user['language'].'/admin_common.php';
require FORUM_ROOT.'lang/'.$forum_user['language'].'/common.php';

require FORUM_ROOT.'lang/'.$forum_user['language'].'/topic.php';

//add your language files
if (file_exists($ext_info['path'].'/lang/'.$forum_user['language'].'/akismet.php'))
	include $ext_info['path'].'/lang/'.$forum_user['language'].'/akismet.php';
else
	include $ext_info['path'].'/lang/English/akismet.php';

$forum_page['crumbs'] = array(
    array($forum_config['o_board_title'], forum_link($forum_url['index'])),
    array($lang_admin_common['Forum administration'], forum_link($forum_url['admin_index'])),
    array('Akismet', forum_link($forum_url['akismet']))
);

require_once  $ext_info['path'].'/akismet.php5.class.php';

$forum_page['form_action']=forum_link($forum_url['akismet']);

ob_start();
if(isset($_GET['id'])) {
	// from an individual post
	$pid = preg_replace('/[^0-9]/', '', $_GET['id']);
	$tid = preg_replace('/[^0-9]/', '', $_GET['topic']);

	$akhquery = array(
		'SELECT'	=> 'p.is_spam',
		'FROM'		=> 'posts AS p',
		'WHERE'		=> 'id=\''.$pid.'\''
	);
	$akhresult = $forum_db->query_build($akhquery) or error(__FILE__, __LINE__);
	list($akspam) = $forum_db->fetch_row($akhresult);
	if($akspam=='0') {

		//update the records
		$akismet = new Akismet($base_url, $forum_config['o_akismet_key']);
		$nakhquery = array(
			'SELECT'	=> 'u.email, p.poster, p.message',
			'FROM'		=> 'posts AS p',
			'JOINS'		=> array(
				array(
					'INNER JOIN'	=> 'users as u',
					'ON'			=> 'u.id=p.poster_id'
				)
			),
			'WHERE'		=> 'p.id=\''.$pid.'\''
		);
		$nakhresult = $forum_db->query_build($nakhquery) or error(__FILE__, __LINE__);
		list($akemail,$akusername,$akmessage) = $forum_db->fetch_row($nakhresult);
		$akismet->setCommentAuthor($akusername);
		$akismet->setCommentAuthorEmail($akemail);
		$akismet->setCommentContent($akmessage);
		$akismet->setCommentType('punbb');
		$akismet->submitSpam();
		$query = array(
			'UPDATE'		=> 'posts',
			'SET'			=> 'is_spam = \'1\'',
			'WHERE'			=> 'id=\''.$pid.'\''
		);
		$retstr=$lang_akismet['Successfully set as spam'];
		$forum_db->query('UPDATE '.$forum_db->prefix.'config SET conf_value=conf_value+1 where conf_name=\'o_akismet_spam_count\' limit 1');

	} elseif($akspam=='1') {

		//update the records
		$query = array(
			'UPDATE'		=> 'posts',
			'SET'			=> 'is_spam = \'0\'',
			'WHERE'			=> 'id=\''.$pid.'\''
		);
		$retstr=$lang_akismet['Successfully set as not spam'];
		$forum_db->query('UPDATE '.$forum_db->prefix.'config SET conf_value=conf_value+1 where conf_name=\'o_akismet_ham_count\' limit 1');

	}

	$forum_db->query_build($query) or error(__FILE__, __LINE__);

	// Regenerate the config cache - and why not! 
	if (!defined('FORUM_CACHE_FUNCTIONS_LOADED'))
		require FORUM_ROOT.'include/cache.php';

	generate_config_cache();
	redirect(forum_link($forum_url['topic'], $tid), $retstr);
}

//akismet form handling
if(isset($_POST['form_sent'])) {
	
	// Validate CSRF token
	if (!isset($_POST['csrf_token']) && (!isset($_GET['csrf_token']) || $_GET['csrf_token'] !== generate_form_token($forum_page['form_action'])))
		csrf_confirm_form();
		
	// Deletion
	if(isset($_POST['delete_posts'])) {

		$del_posts = $_POST['posts'];
		echo '<div style="background:#fff;">';
		foreach($del_posts as $id) {

			$id = intVal($id);
			$query = array(
				'SELECT'	=> 'f.id AS fid, f.forum_name, f.moderators, f.redirect_url, fp.post_replies, fp.post_topics, t.id AS tid, t.subject, t.first_post_id, t.closed, p.poster, p.poster_id, p.message, p.hide_smilies, p.posted',
				'FROM'		=> 'posts AS p',
				'JOINS'		=> array(
					array(
						'INNER JOIN'	=> 'topics AS t',
						'ON'			=> 't.id=p.topic_id'
					),
					array(
						'INNER JOIN'	=> 'forums AS f',
						'ON'			=> 'f.id=t.forum_id'
					),
					array(
						'LEFT JOIN'		=> 'forum_perms AS fp',
						'ON'			=> '(fp.forum_id=f.id AND fp.group_id='.$forum_user['g_id'].')'
					)
				),
				'WHERE'		=> '(fp.read_forum IS NULL OR fp.read_forum=1) AND p.id='.$id
			);
			$result = $forum_db->query_build($query) or error(__FILE__, __LINE__);
			if (!$forum_db->num_rows($result))
				message($lang_common['Bad request']);

			$cur_post = $forum_db->fetch_assoc($result);

			// Sort out who the moderators are and if we are currently a moderator (or an admin)
			$mods_array = ($cur_post['moderators'] != '') ? unserialize($cur_post['moderators']) : array();
			$forum_page['is_admmod'] = ($forum_user['g_id'] == FORUM_ADMIN || ($forum_user['g_moderator'] == '1' && array_key_exists($forum_user['username'], $mods_array))) ? true : false;

			$cur_post['is_topic'] = ($id == $cur_post['first_post_id']) ? true : false;

			// Do we have permission to delete this post?
			if ((($forum_user['g_delete_posts'] == '0' && !$cur_post['is_topic']) ||
				($forum_user['g_delete_topics'] == '0' && $cur_post['is_topic']) ||
				$cur_post['poster_id'] != $forum_user['id'] ||
				$cur_post['closed'] == '1') &&
				!$forum_page['is_admmod'])
				message($lang_common['No permission']);

			if ($cur_post['is_topic'])
				delete_topic($cur_post['tid'], $cur_post['fid']);
			else
				delete_post($id, $cur_post['tid'], $cur_post['fid']);

		}

		// Regenerate the config cache - and why not! 
		if (!defined('FORUM_CACHE_FUNCTIONS_LOADED'))
			require FORUM_ROOT.'include/cache.php';

		generate_config_cache();
		redirect($base_url.'/extensions/akismet/akismet.php', $lang_akismet['Spam Deleted']);

	}

	//not spam = ham
	if(isset($_POST['ham_posts'])) {

		$ham_posts=$_POST['posts'];
		foreach($ham_posts as $hampid) {

			$pid = intVal($hampid);
			
			//akismet submit ham

			//Create Akismet object
			$akismet = new Akismet($base_url, $forum_config['o_akismet_key']);
			$akhquery = array(
				'SELECT'	=> 'u.email, p.poster, p.message',
				'FROM'		=> 'posts AS p',
				'JOINS'		=> array(
					array(
						'INNER JOIN'	=> 'users as u',
						'ON'			=> 'u.id=p.poster_id'
					)
				),
				'WHERE'		=> 'p.id=\''.$pid.'\''
			);
			
			$akhresult = $forum_db->query_build($akhquery) or error(__FILE__, __LINE__);
			list($akemail,$akusername,$akmessage) = $forum_db->fetch_row($akhresult);
			$akismet->setCommentAuthor($akusername);
			$akismet->setCommentAuthorEmail($akemail);
			$akismet->setCommentContent($akmessage);
			$akismet->setCommentType('punbb');
			$akismet->submitHam();
			//end akismet ham
		
			//update the records
			$query = array(
				'UPDATE'		=> 'posts',
				'SET'			=> 'is_spam = \'0\'',
				'WHERE'			=> 'id=\''.$pid.'\''
			);
			$forum_db->query_build($query) or error(__FILE__, __LINE__);
			$forum_db->query('UPDATE '.$forum_db->prefix.'config SET conf_value=conf_value+1 where conf_name=\'o_akismet_ham_count\' limit 1');

		}

		// Regenerate the config cache - and why not!
		if (!defined('FORUM_CACHE_FUNCTIONS_LOADED'))
			require FORUM_ROOT.'include/cache.php';

		generate_config_cache();
		redirect($base_url.'/extensions/akismet/akismet.php', $lang_akismet['Successfully set as not spam']);

	}

}

$query = array(
	'SELECT'	=> 't.subject, u.email, p.id, p.poster, p.poster_id, p.poster_ip, p.poster_email AS guest_email, p.message, p.posted, p.topic_id',
	'FROM'		=> 'posts AS p',
	'JOINS'		=> array(
		array(
			'INNER JOIN'	=> 'topics AS t',
			'ON'			=> 't.id=p.topic_id'
		),
		array(
			'INNER JOIN'	=> 'users as u',
			'ON'			=> 'u.id=p.poster_id'
		)
	),
	'WHERE'		=> 'is_spam = \'1\''
);
$result = $forum_db->query_build($query) or error(__FILE__, __LINE__);

if(!defined('FORUM_PAGE_SECTION'))
	define('FORUM_PAGE_SECTION', 'management');

if(!defined('FORUM_PAGE'))
	define('FORUM_PAGE', 'admin-akismet');

require FORUM_ROOT.'header.php';
?>
	<div class="main-subhead">
		<h2 class="hn"><span><?php echo $lang_akismet['Akismet'] ?></span>
		<?php
			if($forum_config['o_akismet'] == 1) 
				echo ' <small>[<a href="'.$base_url.'/admin/settings.php?section=setup" title="'.$lang_akismet['disable'].'">'.$lang_akismet['enabled'].'</a>]</small>';
			else
				echo ' <small>[<a href="'.$base_url.'/admin/settings.php?section=setup" title="'.$lang_akismet['enable'].'">'.$lang_akismet['not enabled'].'</a>]</small>';
		?>	
		</h2>
	</div>
	
	<div class="main-content">
		<div class="ct-box info-box">
		<?php
			echo '<p>'.sprintf($lang_akismet['Spam Counts'], $forum_config['o_akismet_spam_count'], $forum_config['o_akismet_ham_count']).'</p>';
		?>
		</div>
<?php
if((int)$forum_db->num_rows($result)==0)
{
?>
		<div class="ct-box warn-box">
			<p class="warn"><?php echo $lang_akismet['No spam'] ?></p>
		</div>
	</div>
<?php
}
else
{
?>
		<div class="ct-box warn-box">
			<p class="warn"><strong><?php echo $lang_akismet['Spam'] ?></strong></p>
		</div>
<?php
	if (!defined('FORUM_PARSER_LOADED'))
		require FORUM_ROOT.'include/parser.php';
	$forum_page['item_count'] = 0;	// Keep track of post numbers
	
	$forum_page['finish_at']=(int)$forum_db->num_rows($result);
?>
</div>
<div id="brd-pagepost-top" class="main-pagepost gen-content">
	<p class="paging"><span class="pages"><?php echo $lang_common['Pages']; ?></span> <strong class="first-item">1</strong></p>
</div>

	<div class="main-head">
		<p class="options"><span class="first-item"><a href="#" onclick="return Forum.toggleCheckboxes(document.getElementById('mr-post-actions-form'))"><?php echo $lang_akismet['Select all']; ?></a></span></p>	
		<h2 class="hn"><span><span class="item-info"><?php echo $lang_akismet['Spam']; ?> [<?php echo $forum_page['finish_at']; ?>]</span></span></h2>
	</div>
	<form id="mr-post-actions-form" class="newform" method="post" accept-charset="utf-8" action="<?php echo $forum_page['form_action'] ?>">
	<div class="main-content main-topic">
		<div class="hidden">
			<input type="hidden" name="form_sent" value="1" />
			<input type="hidden" name="csrf_token" value="<?php echo generate_form_token($forum_page['form_action']) ?>" />
		</div>
<?php
	while ($cur_post = $forum_db->fetch_assoc($result)) {

	/* have to check each for the topic individually - sadly */
	$tquery = array(
			'SELECT'	=> 't.subject, t.poster, t.first_post_id, t.posted, t.num_replies',
			'FROM'		=> 'topics AS t',
			'WHERE'		=> 't.id='.$cur_post['topic_id'].' AND t.moved_to IS NULL'
		);
	$tresult = $forum_db->query_build($tquery) or error(__FILE__, __LINE__);

	if (!$forum_db->num_rows($result))
		message($lang_common['Bad request']);

	$cur_topic = $forum_db->fetch_assoc($tresult);

	++$forum_page['item_count'];
	$forum_page['post_ident'] = array();
	$forum_page['post_ident']['num'] = '<span class="post-num" style="margin-right:15px;">'.forum_number_format($forum_page['item_count']).'</span>';
	if ($cur_post['poster_id'] > 1)
		$forum_page['post_ident']['byline'] = '<span class="post-byline"><strong><a title="'.sprintf($lang_topic['Go to profile'], forum_htmlencode($cur_post['poster'])).'" href="'.forum_link($forum_url['user'], $cur_post['poster_id']).'">'.forum_htmlencode($cur_post['poster']).'</a></strong></span>';
	else
		$forum_page['post_ident']['byline'] = '<span class="post-byline"><strong>'.forum_htmlencode($cur_post['poster']).'</strong> <em>Guest</em></span>';

	$forum_page['post_ident']['link'] = '<span class="post-link"><a class="permalink" rel="bookmark" title="'.$lang_topic['Permalink post'].'" href="'.forum_link($forum_url['post'], $cur_post['id']).'">'.format_time($cur_post['posted']).'</a></span>';

	$forum_page['item_status'] = array(
		'post',($forum_page['item_count'] % 2 != 0) ? 'odd' : 'even'
	);
	if ($forum_page['item_count'] == 1)
		$forum_page['item_status']['firstpost'] = 'firstpost';

	if (($forum_page['item_count']) == $forum_page['finish_at'])
		$forum_page['item_status']['lastpost'] = 'lastpost';

	if ($cur_post['id'] == $cur_topic['first_post_id'])
		$forum_page['item_status']['topicpost'] = 'topicpost';
	else
		$forum_page['item_status']['replypost'] = 'replypost';
	?>

	<div class="<?php echo implode(' ', $forum_page['item_status']) ?>">
		<div id="p<?php echo $cur_post['id'] ?>" class="posthead">
			<h3 class="hn post-ident"><?php echo implode(' ', $forum_page['post_ident']) ?></h3>
			<p class="item-select"><input type="checkbox" id="fld<?php echo $cur_post['id']; ?>" name="posts[]" value="<?php echo $cur_post['id']; ?>" /> <label for="fld<?php echo $cur_post['id']; ?>"><?php echo $lang_akismet['Select post'].' '.forum_number_format($forum_page['item_count']); ?></label></p>
		<?php
		if(isset($forum_page['item_status']['topicpost'])) {
			//topics
		?>
			<input type="hidden" name="topicposts[]" value="<?php echo $cur_post['id']; ?>" />
		<?php
		}
		?>
		</div>
		<div class="postbody">
			<div class="post-author">
				<ul class="author-ident">
					<li><span><strong><?php echo $lang_akismet['IP']; ?>:</strong> <em><? echo $cur_post['poster_ip']; ?></em></span></li>
					<li><span><strong><?php echo $lang_akismet['Email']; ?>:</strong> <? echo ($cur_post['poster_id']==1) ? $cur_post['guest_email'] : $cur_post['email']; ?></span></li>
				</ul>
			</div>
			<div class="post-entry">
				<h4 class="entry-title"><?php echo forum_htmlencode($cur_post['subject']); ?></h4>
				<div class="entry-content">
					<?php echo parse_message($cur_post['message'], 1); ?>
				</div>
			</div>
		</div>
	</div>


<?php
	}
?>
	</div>
	<div class="main-options mod-options gen-content">
		<p class="options"><span class="submit first-item">
		<input type="submit" name="delete_posts" value="<?php echo $lang_akismet['Delete Selected Spam']; ?>" /></span> 
		<span class="submit"><input type="submit" name="ham_posts" value="<?php echo $lang_akismet['Selected is ham']; ?>" /></span> 
		<span><a href="#" onclick="return Forum.toggleCheckboxes(document.getElementById('mr-post-actions-form'))"><?php echo $lang_akismet['Select all']; ?></a></span></p>
	</div>
	</form>
	<div class="main-foot">
		<h2 class="hn"><span><span class="item-info"><?php echo $lang_akismet['Spam']; ?> [<?php echo $forum_page['finish_at']; ?>]</span></span></h2>
	</div>
	<div id="brd-pagepost-end" class="main-pagepost gen-content">
	<p class="paging"><span class="pages"><?php echo $lang_common['Pages']; ?></span> <strong class="first-item">1</strong></p>
	</div>
<?php
}
$tpl_temp = forum_trim(ob_get_contents());
$tpl_main = str_replace('<!-- forum_main -->', $tpl_temp, $tpl_main);
ob_end_clean();

require FORUM_ROOT.'footer.php';
?>
