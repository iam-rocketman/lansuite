<?php

define('FIELD_OPTIONAL', 1);
define('HTML_ALLOWED', 1);
define('LSCODE_ALLOWED', 1);
define('HTML_WYSIWYG', 2);
define('LSCODE_BIG', 3);
define('IS_PASSWORD', 1);
define('IS_NEW_PASSWORD', 2);
define('IS_SELECTION', 3);
define('IS_MULTI_SELECTION', 4);
define('IS_FILE_UPLOAD', 5);
define('IS_PICTURE_SELECT', 6);
define('IS_TEXT_MESSAGE', 7);
define('IS_CAPTCHA', 8);

define('READ_DB_PROC', 0);
define('CHECK_ERROR_PROC', 1);

class masterform {

	var $FormFields = array();
	var $Groups = array();
	var $SQLFields = array();
	var $SQLFieldTypes = array();
	var $SQLFieldUnique = array();
	var $DependOn = array();
	var $error = array();
	var $AdditionalDBAfterSelectFunction = '';
	var $AdditionalDBPreUpdateFunction = '';
	var $AdditionalDBUpdateFunction = '';
	var $DependOnStarted = 0;
	var $isChange = false;
	var $FormEncType = '';
	var $PWSecID = 0;
	var $AdditionalKey = '';
  var $AddInsertControllField = '';
  var $AddChangeCondition = '';
  var $NumFields = 0;
  var $insert_id = -1;
  
  function masterform($MFID = 0) {
    $this->MFID = $MFID;
  }
	
  function AddFix($name, $value){
    $this->SQLFields[] = $name;
    $_POST[$name] = $value;
  }

  function AddField($caption, $name, $type = '', $selections = '', $optional = 0, $callback = '', $DependOnThis = 0, $DependOnCriteria = '') {
    $arr = array();
    $arr['caption'] = $caption;
    $arr['name'] = $name;
    $arr['type'] = $type;
    if ($type == IS_FILE_UPLOAD) $this->FormEncType = 'multipart/form-data';
    $arr['optional'] = 0;
    $arr['optional'] = $optional;
    if ($DependOnThis) $this->DependOn[$name] = $DependOnThis;
    $arr['callback'] = $callback;
    $arr['selections'] = $selections;
    $arr['DependOnCriteria'] = $DependOnCriteria;
    $this->FormFields[] = $arr;
    $this->SQLFields[] = $name;
    $this->NumFields++;
  }
  
  function AddGroup($caption = '') {
    if (count($this->FormFields) > 0) {
      $arr = array();
      $arr['caption'] = $caption;
      $arr['fields'] = $this->FormFields;
      $this->Groups[] = $arr;
      $this->FormFields = array();
    }
  }


  // Print form
	function SendForm($BaseURL, $table, $idname = '', $id = 0) {
    global $dsp, $db, $config, $func, $sec, $lang, $templ;

		$this->AddGroup(); // Adds non-group-fields to fake group

    $StartURL = $BaseURL .'&'. $idname .'='. $id;
    if ($id) $this->isChange = true;

    $AddKey = '';
    if ($this->AdditionalKey != '') $AddKey = $this->AdditionalKey .' AND ';
    $InsContName = 'InsertControll'. $this->MFID;

    // If the table entry should be created, or deleted wheter the control field is checked
    if ($this->AddInsertControllField != '') {
      $find_entry = $db->query("SELECT * FROM {$config['tables'][$table]} WHERE $AddKey $idname = ". (int)$id);
      ($db->num_rows($find_entry))? $this->isChange = 1 : $this->isChange = 0;
      $db->free_result($find_entry);
    }

    // Get SQL-Field Types
    $res = $db->query("DESCRIBE {$config['tables'][$table]}");
    while ($row = $db->fetch_array($res)) {
      $SQLFieldTypes[$row['Field']] = $row['Type'];
      if ($row['Key'] == 'PRI' or $row['Key'] == 'UNI') $SQLFieldUnique[$row['Field']] = true;
      else $SQLFieldUnique[$row['Field']] = false;
    }
    $db->free_result($res);

    // Split fields, which consist of more than one
    if ($this->SQLFields) foreach ($this->SQLFields as $key => $val) if (strpos($this->SQLFields[$key], '|') > 0) {
      $subfields = split('\|', $this->SQLFields[$key]);
      if ($subfields) foreach ($subfields as $subfield) $this->SQLFields[] = $subfield;
    }

    // Delete non existing DB fields, from array
    if ($this->SQLFields) foreach ($this->SQLFields as $key => $val) if (!$SQLFieldTypes[$val]) unset($this->SQLFields[$key]);

    // Error-Switch
    switch ($_GET['mf_step']) {
      default:

        // Read current values, if change
        if ($this->isChange) {
          $db_query = '';
          if ($this->SQLFields) foreach ($this->SQLFields as $val) {
            if ($SQLFieldTypes[$val] == 'datetime' or $SQLFieldTypes[$val] == 'date') $db_query .= ", UNIX_TIMESTAMP($val) AS $val";
            else $db_query .= ", $val";
          }

          $row = $db->query_first("SELECT 1 AS found $db_query FROM {$config['tables'][$table]} WHERE $AddKey $idname = ". (int)$id);
          if ($row['found']) foreach ($this->SQLFields as $key => $val) $_POST[$val] = $row[$val];
          else {
            $func->error($lang['mf']['err_invalid_id']);
            return false;
          }
        }
        if ($this->AdditionalDBAfterSelectFunction) $addUpdSuccess = call_user_func($this->AdditionalDBAfterSelectFunction, '');
      break;

      // Check for errors and convert data, if necessary (dates, passwords, ...)
      case 2:
        if ($this->Groups) foreach ($this->Groups as $GroupKey => $group) {
          if ($group['fields']) foreach ($group['fields'] as $FieldKey => $field) if($field['name']) {
            $err = false;

            // Copy WYSIWYG editor variable
            if (($SQLFieldTypes[$field['name']] == 'text' or $SQLFieldTypes[$field['name']] == 'mediumtext' or $SQLFieldTypes[$field['name']] == 'longtext')
              and $field['selections'] == HTML_WYSIWYG) $_POST[$field['name']] = $_POST['FCKeditor1'];

            // If not in DependOn-Group, or DependOn-Group is active
            if (!$this->DependOnStarted or $_POST[$this->DependOnField]) {
              // Convert Post-date to unix-timestap
              if ($SQLFieldTypes[$field['name']] == 'datetime')
                $_POST[$field['name']] = $func->date2unixstamp($_POST[$field['name'].'_value_year'], $_POST[$field['name'].'_value_month'],
                $_POST[$field['name'].'_value_day'], $_POST[$field['name'].'_value_hours'], $_POST[$field['name'].'_value_minutes'], 0);

              if ($SQLFieldTypes[$field['name']] == 'date')
                $_POST[$field['name']] = $func->date2unixstamp($_POST[$field['name'].'_value_year'], $_POST[$field['name'].'_value_month'],
                $_POST[$field['name'].'_value_day'], 0, 0, 0);

              if ($field['type'] == IS_CALLBACK) $err = call_user_func($field['selections'], $field['name'], CHECK_ERROR_PROC);
              if ($err) $this->error[$field['name']] = $err;

              // Check for value
              if (!$field['optional'] and $_POST[$field['name']] == '') $this->error[$field['name']] = $lang['mf']['err_no_value'];

              // Check Int
              elseif (strpos($SQLFieldTypes[$field['name']], 'int') !== false and $SQLFieldTypes[$field['name']] != 'tinyint(1)'
                and $SQLFieldTypes[$field['name']] != "enum('0','1')"
                and $_POST[$field['name']] and (int)$_POST[$field['name']] == 0) $this->error[$field['name']] = $lang['mf']['err_no_integer'];

              // Check date
              elseif (($SQLFieldTypes[$field['name']] == 'datetime' or $SQLFieldTypes[$field['name']] == 'date')
                and !checkdate($_POST[$field['name'].'_value_month'], $_POST[$field['name'].'_value_day'], $_POST[$field['name'].'_value_year']))
                $this->error[$field['name']] = $lang['mf']['err_invalid_date'];
                
              // Check new passwords
              elseif ($field['type'] == IS_NEW_PASSWORD and $_POST[$field['name']] != $_POST[$field['name'].'2'])
                $this->error[$field['name'].'2'] = $lang['mf']['err_pw2'];

              // Check captcha
              elseif ($field['type'] == IS_CAPTCHA and ($_POST['captcha'] == '' or $_COOKIE['image_auth_code'] != md5(strtoupper($_POST['captcha']))))
                $this->error['captcha'] = $lang['mf']['err_captcha'];

              // Callbacks
              elseif ($field['callback']) {
                $err = call_user_func($field['callback'], $_POST[$field['name']]);
                if ($err) $this->error[$field['name']] = $err;
              }

              // Check double uniques
              if (!$this->isChange and $SQLFieldUnique[$field['name']]) {
                $row = $db->query_first("SELECT 1 AS found FROM {$config['tables'][$table]} WHERE {$field['name']} = '{$_POST[$field['name']]}'");
                if ($row['found']) $this->error[$field['name']] = $lang['mf']['err_double_on_unique'];
              }
            }

            // Manage Depend-On-Groups
            if ($this->DependOnStarted >= 1) $this->DependOnStarted--;
            if ($this->DependOnStarted == 0 and array_key_exists($field['name'], $this->DependOn)) {
              $this->DependOnStarted = $this->DependOn[$field['name']];
              $this->DependOnField = $field['name'];
            }

          }
        }

        if (count($this->error) > 0) $_GET['mf_step']--;
      break;
    }


    // Form-Switch
    switch ($_GET['mf_step']) {

      // Output form
      default:
        $sec->unlock($table);
    		$dsp->SetForm($StartURL .'&mf_step=2', '', '', $this->FormEncType);

        // InsertControll check box - the table entry will only be created, if this check box is checked, otherwise the existing entry will be deleted
        if ($this->AddInsertControllField != '') {
          $find_entry = $db->query("SELECT * FROM {$config['tables'][$table]} WHERE $AddKey $idname = ". (int)$id);
          if ($db->num_rows($find_entry)) $_POST[$InsContName] = 1;

          $this->DependOnStarted = $this->NumFields;
          $additionalHTML = "onclick=\"CheckBoxBoxActivate('box_$InsContName', this.checked)\"";
          $dsp->AddCheckBoxRow($InsContName, $this->AddInsertControllField, '', '', $field['optional'], $_POST[$InsContName], '', '', $additionalHTML);
          $dsp->StartHiddenBox('box_'.$InsContName, $_POST[$InsContName]);
        }

        // Output fields
        if ($this->Groups) foreach ($this->Groups as $GroupKey => $group) {
          if ($group['caption']) $dsp->AddFieldsetStart($group['caption']);
          if ($group['fields']) foreach ($group['fields'] as $FieldKey => $field) {

            $additionalHTML = '';
            if (!$field['type']) $field['type'] = $SQLFieldTypes[$field['name']];
            switch ($field['type']) {

              case 'text': // Textarea
                $maxchar = 65535;
              case 'mediumtext':
                if (!$maxchar) $maxchar = 16777215;
              case 'longtext':
                if (!$maxchar) $maxchar = 4294967295;
                if ($field['selections'] == HTML_ALLOWED or $field['selections'] == LSCODE_ALLOWED) $dsp->AddTextAreaPlusRow($field['name'], $field['caption'], $_POST[$field['name']], $this->error[$field['name']], '', '', $field['optional'], $maxchar);
                elseif ($field['selections'] == LSCODE_BIG) $dsp->AddTextAreaPlusRow($field['name'], $field['caption'], $_POST[$field['name']], $this->error[$field['name']], 100, 20, $field['optional'], $maxchar);
                elseif ($field['selections'] == HTML_WYSIWYG) {
                  ob_start();
                  include_once("ext_scripts/FCKeditor/fckeditor.php");
                  $oFCKeditor = new FCKeditor('FCKeditor1') ;
                  $oFCKeditor->BasePath	= 'ext_scripts/FCKeditor/';
                  $oFCKeditor->Value = $_POST[$field['name']];
                  $oFCKeditor->Height = 460;
                  $oFCKeditor->Create();
                  $fcke_content = ob_get_contents();
                  ob_end_clean();
                  $dsp->AddSingleRow($fcke_content);
                  if ($this->error[$field['name']]) $dsp->AddDoubleRow($field['caption'], $dsp->errortext_prefix . $this->error[$field['name']] . $dsp->errortext_suffix);
                }
                else $dsp->AddTextAreaRow($field['name'], $field['caption'], $_POST[$field['name']], $this->error[$field['name']], '', '', $field['optional']);
              break;

              case "enum('0','1')": // Checkbox
              case 'tinyint(1)':
                if ($this->DependOnStarted == 0 and array_key_exists($field['name'], $this->DependOn)) $additionalHTML = "onclick=\"CheckBoxBoxActivate('box_{$field['name']}', this.checked)\"";
                list($field['caption1'], $field['caption2']) = split('\|', $field['caption']);
                if (!$_POST[$field['name']]) unset($_POST[$field['name']]);
                $dsp->AddCheckBoxRow($field['name'], $field['caption1'], $field['caption2'], $this->error[$field['name']], $field['optional'], $_POST[$field['name']], '', '', $additionalHTML);
              break;

              case 'datetime': // Date-Select
                $dsp->AddDateTimeRow($field['name'], $field['caption'], $_POST[$field['name']], $this->error[$field['name']], '', '', '', '', '', $field['optional']);
              break;

              case 'date': // Date-Select
                $dsp->AddDateTimeRow($field['name'], $field['caption'], $_POST[$field['name']], $this->error[$field['name']], '', '', '', '', 1, $field['optional']);
              break;

              case IS_PASSWORD: // Password-Row
                $dsp->AddPasswordRow($field['name'], $field['caption'], $_POST[$field['name']], $this->error[$field['name']], '', $field['optional']);
              break;

              case IS_NEW_PASSWORD: // New-Password-Row
                $PWSecID++;
                $dsp->AddPasswordRow($field['name'], $field['caption'], $_POST[$field['name']], $this->error[$field['name']], '', $field['optional'], "onkeyup=\"CheckPasswordSecurity(this.value, document.images.seclevel{$PWSecID})\"");
                $dsp->AddPasswordRow($field['name'].'2', $field['caption'].' '.$lang['mf']['pw2_caption'], $_POST[$field['name'].'2'], $this->error[$field['name'].'2'], '', $field['optional'], 0);
                $templ['pw_security']['id'] = $PWSecID;
                $dsp->AddDoubleRow('', $dsp->FetchTpl('design/templates/ls_row_pw_security.htm'));
              break;

              case IS_CAPTCHA: // Captcha-Row
                $dsp->AddTextFieldRow('captcha', 'Captcha <img src="ext_scripts/captcha.php">', $_POST['captcha'], $this->error['captcha']);
              break;

              case IS_SELECTION: // Pre-Defined Dropdown
                if ($field['DependOnCriteria']) $addCriteria = ", Array('". implode("', '", $field['DependOnCriteria']) ."')";
                else $addCriteria = '';
                if ($this->DependOnStarted == 0 and array_key_exists($field['name'], $this->DependOn)) $additionalHTML = "onchange=\"DropDownBoxActivate('box_{$field['name']}', this.options[this.options.selectedIndex].value{$addCriteria})\"";
                if (is_array($field['selections'])) {
              		$selections = array();
              		foreach($field['selections'] as $key => $val) {
              			($_POST[$field['name']] == $key) ? $selected = " selected" : $selected = "";
              			$selections[] = "<option$selected value=\"$key\">$val</option>";
              		}
                  $dsp->AddDropDownFieldRow($field['name'], $field['caption'], $selections, $this->error[$field['name']], $field['optional'], $additionalHTML);
                }
              break;

              case IS_MULTI_SELECTION: // Pre-Defined Multiselection
                if (is_array($field['selections'])) {
              		$selections = array();
              		foreach($field['selections'] as $key => $val) {
              		  $selected = '';
                    if ($_POST[$field['name']]) foreach($_POST[$field['name']] as $PostedField) {
                      if ($PostedField == $key) {
                        $selected = ' selected';
                        break;
                      }
                    }
              			$selections[] = "<option value=\"$key\"$selected>$val</option>";
              		}
                  $dsp->AddSelectFieldRow($field['name'], $field['caption'], $selections, $this->error[$field['name']], $field['optional'], 7);
                }
              break;

              case IS_FILE_UPLOAD: // File Upload to path
                if (is_dir($field['selections']))
                  $dsp->AddFileSelectRow($field['name'], $field['caption'], $this->error[$field['name']], '', '', $field['optional']);
                  // Todo: Show current picture (if picture)
                  // Todo: Possibility to keep curent picture, when only changing other data
              break;

              case IS_PICTURE_SELECT: // Picture Dropdown from path
                if (is_dir($field['selections']))
                  $dsp->AddPictureDropDownRow($field['name'], $field['caption'], $field['selections'], $this->error[$field['name']], $field['optional'], $_POST[$field['name']]);
              break;
              
              case IS_TEXT_MESSAGE:
                $dsp->AddDoubleRow($field['caption'], $field['selections']);
              break;

              case IS_CALLBACK:
                $ret = call_user_func($field['selections'], $field['name'], OUTPUT_PROC, $this->error[$field['name']]);
                if ($ret) $dsp->AddDoubleRow($field['caption'], $ret);
              break;

              default: // Normal Textfield
                $dsp->AddTextFieldRow($field['name'], $field['caption'], $_POST[$field['name']], $this->error[$field['name']], '', $field['optional']);
              break;
            }

            // Start HiddenBox
            if ($this->DependOnStarted == 0 and array_key_exists($field['name'], $this->DependOn)) {
              $dsp->StartHiddenBox('box_'.$field['name'], $_POST[$field['name']]);
              $this->DependOnStarted = $this->DependOn[$field['name']] + 1;
              unset($this->DependOn[$field['name']]);
            }
            // Stop HiddenBox, when counter has reached the last box-field
            if ($this->DependOnStarted == 1) $dsp->StopHiddenBox();
            // Decrease counter
            if ($this->DependOnStarted > 0) $this->DependOnStarted--;
          }
          if ($group['caption']) $dsp->AddFieldsetEnd();
        }

    		if ($id) $dsp->AddFormSubmitRow('edit');
    		else $dsp->AddFormSubmitRow('add');
        $dsp->AddContent();
      break;

      // Update DB
      case 2:
#        if (!$this->SQLFields) $func->error('No Fields!');
        if (!$sec->locked($table, $StartURL)) {

          if ($this->Groups) foreach ($this->Groups as $group) if ($group['fields']) foreach ($group['fields'] as $field) {
            // Convert Passwords
            if ($field['type'] == IS_NEW_PASSWORD and $_POST[$field['name']] != '') {
              $_POST[$field['name'] .'_original'] = $_POST[$field['name']];
              $_POST[$field['name']] = md5($_POST[$field['name']]);
            }

            // Upload submitted file
            if ($field['type'] == IS_FILE_UPLOAD) $_POST[$field['name']] = $func->FileUpload($field['name'], $field['selections']);
          }

          if ($this->AdditionalDBPreUpdateFunction) $addUpdSuccess = call_user_func($this->AdditionalDBPreUpdateFunction, $id);
          $ChangeError = false;
          if ($this->AddChangeCondition) $ChangeError = call_user_func($this->AddChangeCondition, $id);

          if ($ChangeError) $func->information($ChangeError);
          else {
            $addUpdSuccess = true;

            // Generate INSERT/UPDATE query
            $db_query = '';
            if ($this->SQLFields) {
              foreach ($this->SQLFields as $key => $val) {
                if ($SQLFieldTypes[$val] == 'datetime' or $SQLFieldTypes[$val] == 'date') $db_query .= "$val = FROM_UNIXTIME(". $_POST[$val]. "), ";
                else $db_query .= "$val = '{$_POST[$val]}', ";
              }
              $db_query = substr($db_query, 0, strlen($db_query) - 2);
  
              // If the table entry should be created, or deleted wheter the control field is checked
              if ($this->AddInsertControllField != '' and !$_POST[$InsContName])
                $db->query("DELETE FROM {$config['tables'][$table]} WHERE $AddKey $idname = ". (int)$id);
  
              // Send query
              else {
                if ($this->isChange) $db->query("UPDATE {$config['tables'][$table]} SET $db_query WHERE $AddKey $idname = ". (int)$id);
                else {
                  $DBInsertQuery = $db_query;
                  if ($this->AdditionalKey != '') $DBInsertQuery .= ', '. $this->AdditionalKey;
                  if ($this->AddInsertControllField) $DBInsertQuery .= ', '. $idname .' = '. (int)$id;
                  $db->query("INSERT INTO {$config['tables'][$table]} SET $DBInsertQuery");
                  $id = $db->insert_id();
                  $this->insert_id = $id;
                  $addUpdSuccess = $id;
                }
              }
            }

            if ($this->AdditionalDBUpdateFunction) $addUpdSuccess = call_user_func($this->AdditionalDBUpdateFunction, $id);
            if ($addUpdSuccess) {
              if ($this->isChange) $func->confirmation($lang['mf']['change_success'], $StartURL);
              else $func->confirmation($lang['mf']['add_success'], $StartURL);
            }
          }
          
          $sec->lock($table);
          return $addUpdSuccess;
          /* Will be
           1) return of AdditionalDBPreUpdateFunction if AddChangeCondition returns true
           2) return of AdditionalDBUpdateFunction if set
           3) Insert_id
          */  
        }
      break;
    }

    return false;
  }
}
?>
