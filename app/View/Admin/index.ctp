<h2>Admin Section</h2>

<table class="table table-striped table-condensed">
<thead>
	<tr>
		<th>
			Anime title
		</th>
		<th>
			User
		</th>
		<th>
			Ip adress
		</th>
		<th> 
			Accept / Deny
		</th>
	</tr>
</thead>
<tbody>
<?php
	foreach($animerequests as $request)
	{
		echo '
		<tr>
			<td>'.$request['AnimeRequest']['title'].'</td>
			<td>'.$this->Html->link($request['user']['nick'],"/user/view/".$request['user']['id']."/".$request['user']['nick']).'</td>
			<td>'.$request['AnimeRequest']['ip_adress'].'</td>
			<td>'.
			$this->Html->link(
				"<i class='icon-ok'></i>",
				"/admin/decideRequest/".$request['AnimeRequest']['id']."/true", 
				array('escape' => false, 'class' => 'btn btn-primary')). " " .
			$this->Html->link(
				"<i class='icon-remove'></i>",
				"/admin/decideRequest/".$request['AnimeRequest']['id']."/false", 
				array('escape' => false, 'class' => 'btn btn-danger')).
			'</td>
		</tr>
		';
	}
?>
</tbody>
</table>