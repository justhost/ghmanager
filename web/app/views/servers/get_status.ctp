<?php
/*
 * Created on 25.12.2010
 *
 * File created for project TeamServer(Git)
 * by nikita
 */
 if(isset($status)) {
    echo $this->Js->object($status);
  }
  else
  {
  	echo "{}";
  }
?>