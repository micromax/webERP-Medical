<?php

/* $Id$*/

/*The supplier transaction uses the SuppTrans class to hold the information about the invoice
the SuppTrans class contains an array of GRNs objects - containing details of GRNs for invoicing and also
an array of GLCodes objects - only used if the AP - GL link is effective */

include('includes/DefineSuppTransClass.php');
/* Session started in header.inc for password checking and authorisation level check */
include('includes/session.inc');
$title = _('Enter Supplier Invoice Against Goods Received');
include('includes/header.inc');

echo '<p class="page_title_text"><img src="'.$rootpath.'/css/'.$theme.'/images/magnifier.png" title="' . _('Dispatch') . '" alt="" />' . ' ' . $title . '</p>';

$Complete=false;
if (!isset($_SESSION['SuppTrans'])){
	prnMsg(_('To enter a supplier transactions the supplier must first be selected from the supplier selection screen') . ', ' . _('then the link to enter a supplier invoice must be clicked on'),'info');
	echo '<br /><a href="' . $rootpath . '/SelectSupplier.php">' . _('Select A Supplier to Enter a Transaction For') . '</a>';
	include('includes/footer.inc');
	exit;
	/*It all stops here if there aint no supplier selected and invoice initiated ie $_SESSION['SuppTrans'] started off*/
}

/*If the user hit the Add to Invoice button then process this first before showing  all GRNs on the invoice
otherwise it wouldnt show the latest additions*/
if (isset($_POST['AddPOToTrans']) AND $_POST['AddPOToTrans']!=''){
	foreach($_SESSION['SuppTransTmp']->GRNs as $GRNTmp) { //loop around temp GRNs array
		if ($_POST['AddPOToTrans']==$GRNTmp->PONo) {
			$_SESSION['SuppTrans']->Copy_GRN_To_Trans($GRNTmp); //copy from  temp GRNs array to entered GRNs array
			$_SESSION['SuppTransTmp']->Remove_GRN_From_Trans($GRNTmp->GRNNo); //remove from temp GRNs array
		}
	}
}

if (isset($_POST['AddGRNToTrans'])){ /*adding a GRN to the invoice */
	foreach($_SESSION['SuppTransTmp']->GRNs as $GRNTmp) {
		if (isset($_POST['GRNNo_' . $GRNTmp->GRNNo])) {
			$_POST['GRNNo_' . $GRNTmp->GRNNo] = true;
		} else {
			$_POST['GRNNo_' . $GRNTmp->GRNNo] = false;
		}
		$Selected = $_POST['GRNNo_' . $GRNTmp->GRNNo];
		if ($Selected==True) {
			$_SESSION['SuppTrans']->Copy_GRN_To_Trans($GRNTmp);
			$_SESSION['SuppTransTmp']->Remove_GRN_From_Trans($GRNTmp->GRNNo);
		}
	}
}

if (isset($_POST['ModifyGRN'])){

	$InputError=False;
	$Hold=False;
	$_POST['This_QuantityInv'] = filter_number_input($_POST['This_QuantityInv']);
	$_POST['QtyRecd'] = filter_number_input($_POST['QtyRecd']);
	$_POST['Prev_QuantityInv'] = filter_number_input($_POST['Prev_QuantityInv']);
	if ($_POST['This_QuantityInv'] >= ($_POST['QtyRecd'] - $_POST['Prev_QuantityInv'])){
		$Complete = True;
	} else {
		$Complete = False;
	}
	if ($_SESSION['Check_Qty_Charged_vs_Del_Qty']==True) {
		if (($_POST['This_QuantityInv']+ $_POST['Prev_QuantityInv'])/($_POST['QtyRecd'] ) > (1+ ($_SESSION['OverChargeProportion'] / 100))){
			prnMsg(_('The quantity being invoiced is more than the outstanding quantity by more than') . ' ' . $_SESSION['OverChargeProportion'] . ' ' .
			 _('percent. The system is set up to prohibit this so will put this invoice on hold until it is authorised'),'warn');
			$Hold = True;
		}
	}
	if (!is_numeric($_POST['ChgPrice']) AND $_POST['ChgPrice']<0){
		$InputError = True;
		prnMsg(_('The price charged in the suppliers currency is either not numeric or negative') . '. ' . _('The goods received cannot be invoiced at this price'),'error');
	} elseif ($_SESSION['Check_Price_Charged_vs_Order_Price'] == True) {
		$_POST['ChgPrice'] = filter_number_input($_POST['ChgPrice']);
		if ($_POST['ChgPrice']/$_POST['OrderPrice'] > (1+ ($_SESSION['OverChargeProportion'] / 100))){
			prnMsg(_('The price being invoiced is more than the purchase order price by more than') . ' ' . $_SESSION['OverChargeProportion'] . '%. ' .
			_('The system is set up to prohibit this so will put this invoice on hold until it is authorised'),'warn');
			$Hold=True;
		}
	}

	if ($InputError==False){
//		$_SESSION['SuppTrans']->Remove_GRN_From_Trans($_POST['GRNNumber']);
		$_SESSION['SuppTrans']->Modify_GRN_To_Trans($_POST['GRNNumber'],
													$_POST['PODetailItem'],
													$_POST['ItemCode'],
													$_POST['ItemDescription'],
													$_POST['QtyRecd'],
													$_POST['Prev_QuantityInv'],
													$_POST['This_QuantityInv'],
													$_POST['OrderPrice'],
													$_POST['ChgPrice'],
													$Complete,
													$_POST['StdCostUnit'],
													$_POST['ShiptRef'],
													$_POST['JobRef'],
													$_POST['GLCode'],
													$Hold,
													0,
													$_POST['DecimalPlaces']);
	}
}

if (isset($_GET['Delete'])){
	$_SESSION['SuppTransTmp']->Copy_GRN_To_Trans($_SESSION['SuppTrans']->GRNs[$_GET['Delete']]);
	$_SESSION['SuppTrans']->Remove_GRN_From_Trans($_GET['Delete']);
}


/*Show all the selected GRNs so far from the SESSION['SuppTrans']->GRNs array */

echo '<table cellpadding="1" class="selection">';
echo '<tr><th colspan="8" class="header">' . _('Invoiced Goods Received Selected') . '</th></tr>';

$tableheader = '<tr>
			<th>' . _('Sequence') . ' #</th>
			<th>' . _('Item Code') . '</th>
			<th>' . _('Description') . '</th>
			<th>' . _('Quantity Charged') . '</th>
			<th>' . _('Price Charge in') . ' ' . $_SESSION['SuppTrans']->CurrCode . '</th>
			<th>' . _('Line Value in') . ' ' . $_SESSION['SuppTrans']->CurrCode . '</th></tr>';

echo $tableheader;

$TotalValueCharged=0;

$i=0;
foreach ($_SESSION['SuppTrans']->GRNs as $EnteredGRN){

	echo '<tr><td>' . $EnteredGRN->GRNNo . '</td>
		<td>' . $EnteredGRN->ItemCode . '</td>
		<td>' . $EnteredGRN->ItemDescription . '</td>
		<td class="number">' . locale_number_format($EnteredGRN->This_QuantityInv,$EnteredGRN->DecimalPlaces) . '</td>
		<td class="number">' . locale_money_format($EnteredGRN->ChgPrice,$_SESSION['SuppTrans']->CurrCode) . '</td>
		<td class="number">' . locale_money_format($EnteredGRN->ChgPrice * $EnteredGRN->This_QuantityInv,$_SESSION['SuppTrans']->CurrCode) . '</td>
		<td><a href="' . htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES, 'UTF-8') . '?Modify=' . $EnteredGRN->GRNNo . '">'. _('Modify') . '</a></td>
		<td><a href="' . htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES, 'UTF-8') . '?Delete=' . $EnteredGRN->GRNNo . '">' . _('Delete') . '</a></td>
	</tr>';

	$TotalValueCharged = $TotalValueCharged + ($EnteredGRN->ChgPrice * $EnteredGRN->This_QuantityInv);

	$i++;
	if ($i>15){
		$i=0;
		echo $tableheader;
	}
}

echo '<tr>
	<th colspan="5" align="right" class="header">' . _('Total Value of Goods Charged') . ':</th>
	<th class="number">' . locale_money_format($TotalValueCharged,$_SESSION['SuppTrans']->CurrCode) . '</th>
</tr>';
echo '</table>';
echo '<br /><div class="centre"><a href="' . $rootpath . '/SupplierInvoice.php">' . _('Back to Invoice Entry') . '</a></div><br />';


/* Now get all the outstanding GRNs for this supplier from the database*/

$SQL = "SELECT grnbatch,
				grnno,
				purchorderdetails.orderno,
				purchorderdetails.unitprice,
				grns.itemcode,
				grns.deliverydate,
				grns.itemdescription,
				grns.qtyrecd,
				grns.quantityinv,
				grns.stdcostunit,
				purchorderdetails.glcode,
				purchorderdetails.shiptref,
				purchorderdetails.jobref,
				purchorderdetails.podetailitem,
				purchorderdetails.assetid,
				stockmaster.decimalplaces
		FROM grns
		INNER JOIN purchorderdetails
			ON  grns.podetailitem=purchorderdetails.podetailitem
		INNER JOIN stockmaster
			ON grns.itemcode=stockmaster.stockid
		WHERE grns.supplierid ='" . $_SESSION['SuppTrans']->SupplierID . "'
			AND grns.qtyrecd - grns.quantityinv > 0
		ORDER BY grns.grnno";
$GRNResults = DB_query($SQL,$db);

if (DB_num_rows($GRNResults)==0){
	prnMsg(_('There are no outstanding goods received from') . ' ' . $_SESSION['SuppTrans']->SupplierName . ' ' . _('that have not been invoiced by them') . '<br />' . _('The goods must first be received using the link below to select purchase orders to receive'),'warn');
	echo '<div class="centre"><p><a href="'.$rootpath.'/PO_SelectOSPurchOrder.php?SupplierID=' . $_SESSION['SuppTrans']->SupplierID .'">' .
		_('Select Purchase Orders to Receive') .'</a></p></div>';
	include('includes/footer.inc');
	exit;
}

/*Set up a table to show the GRNs outstanding for selection */
echo '<form action="' . htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES, 'UTF-8') . '" method="post">';
echo '<input type="hidden" name="FormID" value="' . $_SESSION['FormID'] . '" />';

if (!isset( $_SESSION['SuppTransTmp'])){
	$_SESSION['SuppTransTmp'] = new SuppTrans;
	while ($myrow=DB_fetch_array($GRNResults)){

		$GRNAlreadyOnInvoice = False;

		foreach ($_SESSION['SuppTrans']->GRNs as $EnteredGRN){
			if ($EnteredGRN->GRNNo == $myrow['grnbatch']) {
				$GRNAlreadyOnInvoice = True;
			}
		}
		if ($GRNAlreadyOnInvoice == False){
			$_SESSION['SuppTransTmp']->Add_GRN_To_Trans($myrow['grnno'],
														$myrow['podetailitem'],
														$myrow['itemcode'],
														$myrow['itemdescription'],
														$myrow['qtyrecd'],
														$myrow['quantityinv'],
														$myrow['qtyrecd'] - $myrow['quantityinv'],
														$myrow['unitprice'],
														$myrow['unitprice'],
														$Complete,
														$myrow['stdcostunit'],
														$myrow['shiptref'],
														$myrow['jobref'],
														$myrow['glcode'],
														$myrow['orderno'],
														$myrow['assetid'],
														0,
														$myrow['decimalplaces']);
		}
	}
}

//if (isset($_POST['GRNNo']) AND $_POST['GRNNo']!=''){
if (isset($_GET['Modify'])){
	$GRNNo = $_GET['Modify'];
	$GRNTmp = $_SESSION['SuppTrans']->GRNs[$GRNNo];

	echo '<table class="selection">';
	echo '<tr><th colspan="10" class="header">' . _('GRN Selected For Adding To A Purchase Invoice') . '</th></tr>';
	echo '<tr bgcolor=#800000>
			<th>' . _('Sequence') . ' #</th>
			<th>' . _('Item') . '</th>
			<th>' . _('Qty Outstanding') . '</th>
			<th>' . _('Qty Invoiced') . '</th>
			<th>' . _('Order Price in') . ' ' .  $_SESSION['SuppTrans']->CurrCode . '</th>
			<th>' . _('Actual Price in') . ' ' .  $_SESSION['SuppTrans']->CurrCode . '</th>
		</tr>';

	echo '<tr>
		<td>' . $GRNTmp->GRNNo . '</td>
		<td>' . $GRNTmp->ItemCode . ' ' . $GRNTmp->ItemDescription . '</td>
		<td class="number">' . locale_number_format($GRNTmp->QtyRecd - $GRNTmp->Prev_QuantityInv, $GRNTmp->DecimalPlaces) . '</td>
		<td><input type="text" class="number" name="This_QuantityInv" value="' . locale_number_format($GRNTmp->This_QuantityInv, $GRNTmp->DecimalPlaces) . '" size="11" maxlength="10" /></td>
		<td class="number">' . locale_money_format($GRNTmp->OrderPrice, $_SESSION['SuppTrans']->CurrCode) . '</td>
		<td><input type="text" class="number" name="ChgPrice" value="' . locale_money_format($GRNTmp->ChgPrice, $_SESSION['SuppTrans']->CurrCode) . '" size="11" maxlength="10" /></td>
	</tr>';
	echo '</table>';

/*	if ($myrow['closed']==1){ //Shipment is closed so pre-empt problems later by warning the user - need to modify the order first
		echo '<input type="hidden" name='ShiptRef' value='' />";
		echo "Unfortunately, the shipment that this purchase order line item was allocated to has been closed - if you add this item to the transaction then no shipments will not be updated. If you wish to allocate the order line item to a different shipment the order must be modified first.";
	} else {	*/
	echo '<input type="hidden" name="ShiptRef" value="' . $GRNTmp->ShiptRef . '" />';
//	}

	echo '<div class="centre"><p><button type="submit" name="ModifyGRN">' . _('Modify Line') . '</button></p></div>';

	echo '<input type="hidden" name="GRNNumber" value="' . $GRNTmp->GRNNo . '" />';
	echo '<input type="hidden" name="ItemCode" value="' . $GRNTmp->ItemCode . '" />';
	echo '<input type="hidden" name="ItemDescription" value="' . $GRNTmp->ItemDescription . '" />';
	echo '<input type="hidden" name="QtyRecd" value="' . $GRNTmp->QtyRecd . '" />';
	echo '<input type="hidden" name="Prev_QuantityInv" value="' . $GRNTmp->Prev_QuantityInv . '" />';
	echo '<input type="hidden" name="OrderPrice" value="' . $GRNTmp->OrderPrice . '" />';
	echo '<input type="hidden" name="StdCostUnit" value=' . $GRNTmp->StdCostUnit . '" />';
	echo '<input type="hidden" name="JobRef" value="' . $GRNTmp->JobRef . '" />';
	echo '<input type="hidden" name="GLCode" value="' . $GRNTmp->GLCode . '" />';
	echo '<input type="hidden" name="PODetailItem" value="' . $GRNTmp->PODetailItem . '" />';
	echo '<input type="hidden" name="AssetID" value="' . $GRNTmp->AssetID . '" />';
	echo '<input type="hidden" name="DecimalPlaces" value="' . $GRNTmp->DecimalPlaces . '" />';
} else {
	if (count( $_SESSION['SuppTransTmp']->GRNs)>0){   /*if there are any outstanding GRNs then */
		echo '<table cellpadding="1" class="selection">';
		echo '<tr><th colspan="10" class="header">' . _('Goods Received Yet to be Invoiced From') . ' ' . $_SESSION['SuppTrans']->SupplierName.'</th></tr>';

		$tableheader = '<tr><th>' . _('Select') . '</th>
				<th>' . _('Sequence') . ' #</th>
				<th>' . _('Order') . '</th>
				<th>' . _('Item Code') . '</th>
				<th>' . _('Description') . '</th>
				<th>' . _('Total Qty Received') . '</th>
				<th>' . _('Qty Already Invoiced') . '</th>
				<th>' . _('Qty Yet To Invoice') . '</th>
				<th>' . _('Order Price in') . ' ' . $_SESSION['SuppTrans']->CurrCode . '</th>
				<th>' . _('Line Value in') . ' ' . $_SESSION['SuppTrans']->CurrCode . '</th>
				</tr>';

		$POs = array();
		echo $tableheader;
		foreach ($_SESSION['SuppTransTmp']->GRNs as $GRNTmp){

			$_SESSION['SuppTransTmp']->GRNs[$GRNTmp->GRNNo]->This_QuantityInv = $GRNTmp->QtyRecd - $GRNTmp->Prev_QuantityInv;

			if (isset($POs[$GRNTmp->PONo]) and $POs[$GRNTmp->PONo] != $GRNTmp->PONo) {
				$POs[$GRNTmp->PONo] = $GRNTmp->PONo;
				echo '<tr><td><button type="submit" name="AddPOToTrans" value="' . $GRNTmp->PONo . '" />' . $GRNTmp->PONo . '</button></td><td colspan="3" />' . _('Add Whole PO to Invoice') . '</td></tr>';
			}
			if (isset($_POST['SelectAll'])) {
				echo '<tr><td><input type="checkbox" checked name="GRNNo_' . $GRNTmp->GRNNo . '" /></td>';
			} else {
				echo '<tr><td><input type="checkbox" name="GRNNo_' . $GRNTmp->GRNNo . '" /></td>';
			}
			echo '<td>' . $GRNTmp->GRNNo . '</td>
					<td>' . $GRNTmp->PONo . '</td>
					<td>' . $GRNTmp->ItemCode . '</td>
					<td>' . $GRNTmp->ItemDescription . '</td>
					<td class="number">' . locale_number_format($GRNTmp->QtyRecd,$GRNTmp->DecimalPlaces) . '</td>
					<td class="number">' . locale_number_format($GRNTmp->Prev_QuantityInv,$GRNTmp->DecimalPlaces) . '</td>
					<td class="number">' . locale_number_format(($GRNTmp->QtyRecd - $GRNTmp->Prev_QuantityInv),$GRNTmp->DecimalPlaces) . '</td>
					<td class="number">' . locale_money_format($GRNTmp->OrderPrice, $_SESSION['SuppTrans']->CurrCode) . '</td>
					<td class="number">' . locale_money_format($GRNTmp->OrderPrice * ($GRNTmp->QtyRecd - $GRNTmp->Prev_QuantityInv),$_SESSION['SuppTrans']->CurrCode) . '</td>
				</tr>';
		}
		echo '</table>';
		echo '<br /><div class="centre"><button type="submit" name="SelectAll">' . _('Select All') . '</button>';
		echo '<button type="submit" name="DeSelectAll">' . _('Deselect All') . '</button>';
		echo '<br /><button type="submit" name="AddGRNToTrans">' . _('Add to Invoice') . '</button></div>';
	}
}

echo '</form>';
include('includes/footer.inc');
?>