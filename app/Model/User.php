<?php
class User extends AppModel {
	var $helpers = array('Gravatar');
	var $name = 'User';
	var $useTable = 'users';

	/*var $displayField = 'name';*/

	public $hasMany = array( 'AnimelistEntry' );

	//var $virtualFields = array('gravatar' => 'LOWER(MD5(User.email))');
}
?>
