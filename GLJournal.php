<?php

/* $Id$*/

include('includes/DefineJournalClass.php');

include('includes/session.inc');
$title = _('Journal Entry');

include('includes/header.inc');
include('includes/SQL_CommonFunctions.inc');

if (isset($_GET['NewJournal']) and $_GET['NewJournal'] == 'Yes' AND isset($_SESSION['JournalDetail'])){
	unset($_SESSION['JournalDetail']->GLEntries);
	unset($_SESSION['JournalDetail']);
}

if (!isset($_SESSION['JournalDetail'])){
	$_SESSION['JournalDetail'] = new Journal;

	/* Make an array of the defined bank accounts - better to make it now than do it each time a line is added
	Journals cannot be entered against bank accounts GL postings involving bank accounts must be done using
	a receipt or a payment transaction to ensure a bank trans is available for matching off vs statements */

	$SQL = "SELECT accountcode FROM bankaccounts";
	$result = DB_query($SQL,$db);
	$i=0;
	while ($Act = DB_fetch_row($result)){
		$_SESSION['JournalDetail']->BankAccounts[$i]= $Act[0];
		$i++;
	}

}

if (isset($_POST['JournalProcessDate'])){
	$_SESSION['JournalDetail']->JnlDate=$_POST['JournalProcessDate'];

	if (!Is_Date($_POST['JournalProcessDate'])){
		prnMsg(_('The date entered was not valid please enter the date to process the journal in the format'). $_SESSION['DefaultDateFormat'],'warn');
		$_POST['CommitBatch']='Do not do it the date is wrong';
	}
}
if (isset($_POST['JournalType'])){
	$_SESSION['JournalDetail']->JournalType = $_POST['JournalType'];
}

if (isset($_POST['CommitBatch'])){

 /* once the GL analysis of the journal is entered
  process all the data in the session cookie into the DB
  A GL entry is created for each GL entry
*/

	$PeriodNo = GetPeriod($_SESSION['JournalDetail']->JnlDate,$db);

     /*Start a transaction to do the whole lot inside */
	$result = DB_Txn_Begin($db);

	$TransNo = GetNextTransNo( 0, $db);

	foreach ($_SESSION['JournalDetail']->GLEntries as $JournalItem) {
		$SQL = "INSERT INTO gltrans (type,
						typeno,
						trandate,
						periodno,
						account,
						defaulttag,
						narrative,
						amount,
						tag)
					VALUES (0,
						'" . $TransNo . "',
						'" . FormatDateForSQL($_SESSION['JournalDetail']->JnlDate) . "',
						'" . $PeriodNo . "',
						'" . $JournalItem->GLCode . "',
						'" . $_SESSION['DefaultTag'] . "',
						'" . $JournalItem->Narrative . "',
						'" . $JournalItem->Amount . "',
						'" . $JournalItem->tag."'
					)";
		$ErrMsg = _('Cannot insert a GL entry for the journal line because');
		$DbgMsg = _('The SQL that failed to insert the GL Trans record was');
		$result = DB_query($SQL,$db,$ErrMsg,$DbgMsg,true);

		if ($_POST['JournalType']=='Reversing'){
			$SQL = "INSERT INTO gltrans (type,
							typeno,
							trandate,
							periodno,
							account,
							defaulttag,
							narrative,
							amount,
							tag)
						VALUES (0,
							'" . $TransNo . "',
							'" . FormatDateForSQL($_SESSION['JournalDetail']->JnlDate) . "',
							'" . ($PeriodNo + 1) . "',
							'" . $JournalItem->GLCode . "',
							'" . $_SESSION['DefaultTag'] . "',
							'Reversal - " . $JournalItem->Narrative . "',
							-" . $JournalItem->Amount .",
							'".$JournalItem->tag."'
						)";

			$ErrMsg =_('Cannot insert a GL entry for the reversing journal because');
			$DbgMsg = _('The SQL that failed to insert the GL Trans record was');
			$result = DB_query($SQL,$db,$ErrMsg,$DbgMsg,true);

		}
	}

	$ErrMsg = _('Cannot commit the changes');
	$result= DB_Txn_Begin($db);

	prnMsg(_('Journal').' ' . $TransNo . ' '._('has been successfully entered'),'success');

	echo '<br /><div class="centre"><a href="PDFGLJournal.php?JournalNo='.$TransNo.'">'._('Print this Journal').'</a>';

	unset($_POST['JournalProcessDate']);
	unset($_POST['JournalType']);
	unset($_SESSION['JournalDetail']->GLEntries);
	unset($_SESSION['JournalDetail']);

	/*Set up a newy in case user wishes to enter another */
	echo '<br /><a href="' . htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES, 'UTF-8') . '?NewJournal=Yes">'._('Enter Another General Ledger Journal').'</a></div>';
	/*And post the journal too */
	include ('includes/GLPostings.inc');
	include ('includes/footer.inc');
	exit;

} elseif (isset($_GET['Delete'])){

	/* User hit delete the line from the journal */
	$_SESSION['JournalDetail']->Remove_GLEntry($_GET['Delete']);

} elseif (isset($_POST['Process'])){ //user hit submit a new GL Analysis line into the journal
	if($_POST['GLCode']!='') {
		$extract = explode(' - ',$_POST['GLCode']);
		$_POST['GLCode'] = $extract[0];
	}
	if($_POST['Debit']>0) {
		$_POST['GLAmount'] = filter_currency_input($_POST['Debit']);
	} elseif($_POST['Credit']>0) {
		$_POST['GLAmount'] = '-' . filter_currency_input($_POST['Credit']);
	}
	if ($_POST['GLManualCode'] != '' AND is_numeric($_POST['GLManualCode'])){
		// If a manual code was entered need to check it exists and isnt a bank account
		$AllowThisPosting = true; //by default
		if ($_SESSION['ProhibitJournalsToControlAccounts'] == 1){
			if ($_SESSION['CompanyRecord']['gllink_debtors'] == '1' AND $_POST['GLManualCode'] == $_SESSION['CompanyRecord']['debtorsact']){
				prnMsg(_('GL Journals involving the debtors control account cannot be entered. The general ledger debtors ledger (AR) integration is enabled so control accounts are automatically maintained by webERP. This setting can be disabled in System Configuration'),'warn');
				$AllowThisPosting = false;
			}
			if ($_SESSION['CompanyRecord']['gllink_creditors'] == '1' AND $_POST['GLManualCode'] == $_SESSION['CompanyRecord']['creditorsact']){
				prnMsg(_('GL Journals involving the creditors control account cannot be entered. The general ledger creditors ledger (AP) integration is enabled so control accounts are automatically maintained by webERP. This setting can be disabled in System Configuration'),'warn');
				$AllowThisPosting = false;
			}
		}
		if (in_array($_POST['GLManualCode'], $_SESSION['JournalDetail']->BankAccounts)) {
			prnMsg(_('GL Journals involving a bank account cannot be entered') . '. ' . _('Bank account general ledger entries must be entered by either a bank account receipt or a bank account payment'),'info');
			$AllowThisPosting = false;
		}

		if ($AllowThisPosting) {
			$SQL = "SELECT accountname
							FROM chartmaster
							WHERE accountcode='" . $_POST['GLManualCode'] . "'";
			$Result=DB_query($SQL,$db);

			if (DB_num_rows($Result)==0){
				prnMsg(_('The manual GL code entered does not exist in the database') . ' - ' . _('so this GL analysis item could not be added'),'warn');
				unset($_POST['GLManualCode']);
			} else {
				$myrow = DB_fetch_array($Result);
				$_SESSION['JournalDetail']->add_to_glanalysis($_POST['GLAmount'], $_POST['GLNarrative'], $_POST['GLManualCode'], $myrow['accountname'], $_POST['tag']);
			}
		}
	} else {
		$AllowThisPosting =true; //by default
		if ($_SESSION['ProhibitJournalsToControlAccounts'] == 1){
			if ($_SESSION['CompanyRecord']['gllink_debtors'] == '1' AND $_POST['GLCode'] == $_SESSION['CompanyRecord']['debtorsact']){
				prnMsg(_('GL Journals involving the debtors control account cannot be entered. The general ledger debtors ledger (AR) integration is enabled so control accounts are automatically maintained by webERP. This setting can be disabled in System Configuration'),'warn');
				$AllowThisPosting = false;
			}
			if ($_SESSION['CompanyRecord']['gllink_creditors'] == '1' AND $_POST['GLCode'] == $_SESSION['CompanyRecord']['creditorsact']){
				prnMsg(_('GL Journals involving the creditors control account cannot be entered. The general ledger creditors ledger (AP) integration is enabled so control accounts are automatically maintained by webERP. This setting can be disabled in System Configuration'),'warn');
				$AllowThisPosting = false;
			}
		}
		if ($_POST['GLCode'] == '' and $_POST['GLManualCode'] == '') {
			prnMsg(_('You must select a GL account code'),'info');
			$AllowThisPosting = false;
		}

		if (in_array($_POST['GLCode'], $_SESSION['JournalDetail']->BankAccounts)) {
			prnMsg(_('GL Journals involving a bank account cannot be entered') . '. ' . _('Bank account general ledger entries must be entered by either a bank account receipt or a bank account payment'),'warn');
			$AllowThisPosting = false;
		}

		if ($AllowThisPosting){
			if (!isset($_POST['GLAmount'])) {
				$_POST['GLAmount']=0;
			}
			$SQL = "SELECT accountname FROM chartmaster WHERE accountcode='" . $_POST['GLCode'] . "'";
			$Result=DB_query($SQL,$db);
			$myrow=DB_fetch_array($Result);
			$_SESSION['JournalDetail']->add_to_glanalysis($_POST['GLAmount'], $_POST['GLNarrative'], $_POST['GLCode'], $myrow['accountname'], $_POST['tag']);
		}
	}

	/*Make sure the same receipt is not double processed by a page refresh */
	$Cancel = 1;
	unset($_POST['Credit']);
	unset($_POST['Debit']);
	unset($_POST['tag']);
	unset($_POST['GLManualCode']);
	unset($_POST['GLNarrative']);
}

if (isset($Cancel)){
	unset($_POST['Credit']);
	unset($_POST['Debit']);
	unset($_POST['GLAmount']);
	unset($_POST['GLCode']);
	unset($_POST['tag']);
	unset($_POST['GLManualCode']);
}

echo '<form action="' . htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES, 'UTF-8') . '" method="post" name="form">';
echo '<input type="hidden" name="FormID" value="' . $_SESSION['FormID'] . '" />';

echo '<p class="page_title_text"><img src="'.$rootpath.'/css/'.$theme.'/images/maintenance.png" title="' . _('Search') . '" alt="" />' . ' ' . $title.'</p>';

// A new table in the first column of the main table

if (!Is_Date($_SESSION['JournalDetail']->JnlDate)){
	// Default the date to the last day of the previous month
	$_SESSION['JournalDetail']->JnlDate = Date($_SESSION['DefaultDateFormat'],mktime(0,0,0,date('m'),0,date('Y')));
}

echo '<table class="selection">
		<tr>
			<th colspan="5" class="header">' . _('Journal Header') . '</th>
		</tr>
		<tr>
			<td></td><td>'._('Date to Process Journal').':</td>
			<td><input type="text" class="date" alt="'.$_SESSION['DefaultDateFormat'].'" name="JournalProcessDate" maxlength="10" size="11" value="' . $_SESSION['JournalDetail']->JnlDate . '" /></td>';
echo '<td>' . _('Type') . ':</td>
		<td><select name="JournalType">';

if (isset($_POST['JournalType'] ) and $_POST['JournalType'] == 'Reversing'){
	echo '<option selected="True" value = "Reversing">' . _('Reversing').'</option>';
	echo '<option value = "Normal">' . _('Normal').'</option>';
} else {
	echo '<option value = "Reversing">' . _('Reversing').'</option>';
	echo '<option selected="True" value = "Normal">' . _('Normal').'</option>';
}

echo '</select></td>
		</tr>
	</table>';
/* close off the table in the first column  */

echo '<br />';
echo '<table class="selection" width="70%">';
/* Set upthe form for the transaction entry for a GL Payment Analysis item */

echo '<tr><th colspan="3" class="header">' . _('Journal Line Entry') . '</th></tr>';

/*now set up a GLCode field to select from avaialble GL accounts */
echo '<tr><th>' . _('GL Tag') . '</th>';
echo '<th>' . _('GL Account Code') . '</th>';
echo '<th>' . _('Select GL Account') . '</th></tr>';

/* Set upthe form for the transaction entry for a GL Payment Analysis item */

//Select the tag
echo '<tr><td><select name="tag">';

$SQL = "SELECT tagref,
			tagdescription
		FROM tags
		ORDER BY tagref";

$result=DB_query($SQL,$db);
echo '<option value="0">0 - None</option>';
while ($myrow=DB_fetch_array($result)){
	if (isset($_POST['tag']) and $_POST['tag']==$myrow['tagref']){
		echo '<option selected="True" value="' . $myrow['tagref'] . '">' . $myrow['tagref'].' - ' .$myrow['tagdescription'].'</option>';
	} else {
		echo '<option value="' . $myrow['tagref'] . '">' . $myrow['tagref'].' - ' .$myrow['tagdescription'].'</option>';
	}
}
echo '</select></td>';
// End select tag

if (!isset($_POST['GLManualCode'])) {
	$_POST['GLManualCode']='';
}
echo '<td><input class="number" type="text" name="GLManualCode" Maxlength="12" size="12" onChange="inArray(this.value, GLCode.options,'. "'".'The account code '."'".'+ this.value+ '."'".' doesnt exist'."'".')"' . ' value="'. $_POST['GLManualCode'] .'"  /></td>';

$sql="SELECT accountcode,
			accountname
		FROM chartmaster
		ORDER BY accountcode";

$result=DB_query($sql, $db);
echo '<td><select name="GLCode" onChange="return assignComboToInput(this,'.'GLManualCode'.')">';
echo '<option value="">' . _('Select a general ledger account code') . '</option>';
while ($myrow=DB_fetch_array($result)){
	if (isset($_POST['tag']) and $_POST['tag']==$myrow['accountcode']){
		echo '<option selected="True" value="' . $myrow['accountcode'] . '">' . $myrow['accountcode'].' - ' .htmlentities($myrow['accountname'], ENT_QUOTES,'UTF-8').'</option>';
	} else {
		echo '<option value="' . $myrow['accountcode'] . '">' . $myrow['accountcode'].' - ' .htmlentities($myrow['accountname'], ENT_QUOTES,'UTF-8').'</option>';
	}
}
echo '</select></td>';

if (!isset($_POST['GLNarrative'])) {
	$_POST['GLNarrative'] = '';
}
if (!isset($_POST['Credit'])) {
	$_POST['Credit'] = '';
}
if (!isset($_POST['Debit'])) {
	$_POST['Debit'] = '';
}

echo '</tr><tr>
			<th>' . _('Debit') . '</th>
			<td><input type="text" class="number" Name = "Debit" onChange="eitherOr(this, '.'Credit'.')" maxlength="12" size="10" value="' . $_POST['Debit'] . '" /></td>';
echo '</tr><tr>
			<th>' . _('Credit') . '</th>
			<td><input type="text" class="number" Name = "Credit" onChange="eitherOr(this, '.'Debit'.')"  maxlength="12" size="10" value="' . $_POST['Credit'] . '" /></td>';
echo '</tr><tr><td></td><td></td><th>'. _('Narrative'). '</th>';
echo '</tr><tr><th></th><th>' . _('GL Narrative') . '</th>';

echo '<td><input type="text" name="GLNarrative" maxlength="100" size="100" value="' . $_POST['GLNarrative'] . '" /></td>';

echo '</tr></table><br />'; /*Close the main table */
echo '<div class="centre"><button type="submit" name="Process">' . _('Accept') . '</button></div><br /><br />';


echo '<table class="selection" width="85%">';

echo '<tr><th colspan="6" class="header">' . _('Journal Summary') . '</th></tr>';
echo '<tr>
		<th>'._('GL Tag').'</th>
		<th>'._('GL Account').'</th>
		<th>'._('Debit').'</th>
		<th>'._('Credit').'</th>
		<th>'._('Narrative').'</th></tr>';

$debittotal=0;
$credittotal=0;
$j=0;

foreach ($_SESSION['JournalDetail']->GLEntries as $JournalItem) {
	if ($j==1) {
		echo '<tr class="OddTableRows">';
		$j=0;
	} else {
		echo '<tr class="EvenTableRows">';
		$j++;
	}
	$sql="SELECT tagdescription
		FROM tags
		WHERE tagref='".$JournalItem->tag . "'";
	$result=DB_query($sql, $db);
	$myrow=DB_fetch_row($result);
	if ($JournalItem->tag==0) {
		$tagdescription='None';
	} else {
		$tagdescription=$myrow[0];
	}
	echo '<td>' . $JournalItem->tag . ' - ' . $tagdescription . '</td>';
	echo '<td>' . $JournalItem->GLCode . ' - ' . $JournalItem->GLActName . '</td>';
	if($JournalItem->Amount>0) {
		echo '<td class="number">' . locale_money_format($JournalItem->Amount,$_SESSION['CompanyRecord']['currencydefault']) . '</td><td></td>';
		$debittotal=$debittotal+$JournalItem->Amount;
	} elseif($JournalItem->Amount<0) {
		$credit=(-1 * $JournalItem->Amount);
		echo '<td></td>
			<td class="number">' . locale_money_format($credit,$_SESSION['CompanyRecord']['currencydefault']) . '</td>';
		$credittotal=$credittotal+$credit;
	}

	echo '<td>' . $JournalItem->Narrative  . '</td>
			<td><a href="' . htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES, 'UTF-8') . '?Delete=' . $JournalItem->ID . '">'._('Delete').'</a></td>
		</tr>';
}

echo '<tr class="EvenTableRows"><td></td>
		<td class="number"><b>' . _('Total') .  '</b></td>
		<td class="number"><b>' . locale_money_format($debittotal,$_SESSION['CompanyRecord']['currencydefault']) . '</b></td>
		<td class="number"><b>' . locale_money_format($credittotal,$_SESSION['CompanyRecord']['currencydefault']) . '</b></td></tr>';
if ($debittotal!=$credittotal) {
	echo '<td align="center" style="background-color: #fddbdb"><b>' . _('Required to balance') .' - </b>' .
		locale_money_format(abs($debittotal-$credittotal),$_SESSION['CompanyRecord']['currencydefault']);
}
if ($debittotal>$credittotal) {
	echo ' ' . _('Credit') . '</td></tr>';
} else if ($debittotal<$credittotal) {
	echo ' ' . _('Debit') . '</td></tr>';
}
echo '</table>';

if (ABS($_SESSION['JournalDetail']->JournalTotal)<0.001 AND $_SESSION['JournalDetail']->GLItemCounter > 0){
	echo '<br /><br /><div class="centre"><button type="submit" name="CommitBatch">'._('Accept and Process Journal').'</button></div>';
} elseif(count($_SESSION['JournalDetail']->GLEntries)>0) {
	echo '<br /><br />';
	prnMsg(_('The journal must balance ie debits equal to credits before it can be processed'),'warn');
}

if (!isset($_GET['NewJournal']) or $_GET['NewJournal']=='') {
	echo '<script>defaultControl(document.form.GLManualCode);</script>';
} else {
	echo '<script>defaultControl(document.form.JournalProcessDate);</script>';
}

echo '</form>';
include('includes/footer.inc');
?>