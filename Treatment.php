<?php

require_once 'Config.php';

class Treatment extends Config{
    public function get_treatment(){

    $query  = "SELECT * FROM treatment";
    $result = mysqli_query($this->connection, $query);

    if(mysqli_num_rows($result)>0){
        $treatments = mysqli_fetch_all($result, MYSQLI_ASSOC);
        echo json_encode(['status'=>200, 'treatment'=>$treatments]);
    } else{
        echo json_encode(['status'=>400, 'message'=> 'No treatment Found']);
    }

    // print_r($result);
    }
}

// $treatment = new Treatment();
// $treatment->get_treatment();
