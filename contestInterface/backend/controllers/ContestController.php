<?php

class ContestController extends Controller
{

    public function loadData()
    {
        global $tinyOrm, $config;
        if (!isset($_SESSION["teamID"])) {
            if (!isset($_POST["groupPassword"])) {
                exitWithJsonFailure("Mot de passe manquant");
            }
            if (!isset($_POST["teamID"])) {
                exitWithJsonFailure("Équipe manquante");
            }
            if (!isset($_SESSION["groupID"])) {
                exitWithJsonFailure("Groupe non chargé");
            }
            $password = strtolower(trim($_POST["groupPassword"]));
            reloginTeam($this->db, $password, $_POST["teamID"]);
        }
        $teamID = $_SESSION["teamID"];
        $stmt = $this->db->prepare("UPDATE `team` SET `createTime` = UTC_TIMESTAMP() WHERE `ID` = :teamID AND `createTime` IS NULL");
        $stmt->execute(array("teamID" => $teamID));

        $questionsData = getQuestions($this->db, $_SESSION["contestID"], $_SESSION["subsetsSize"], $teamID);
        $mode = null;
        if (isset($_SESSION['mysqlOnly']) && $_SESSION['mysqlOnly']) {
            $mode = 'mysql';
        }
        try {
            $results = $tinyOrm->select('team_question', array('questionID', 'answer', 'ffScore', 'score'), array('teamID' => $teamID), null, $mode);
        } catch (Aws\DynamoDb\Exception\DynamoDbException $e) {
            if (strval($e->getAwsErrorCode()) != 'ConditionalCheckFailedException') {
                error_log($e->getAwsErrorCode() . " - " . $e->getAwsErrorType());
                error_log('DynamoDB error retrieving team_questions for teamID: ' . $teamID);
            }
            $results = [];
        }
        $answers = array();
        $scores = array();
        foreach ($results as $row) {
            if (isset($row['answer'])) {
                $answers[$row['questionID']] = $row['answer'];
            }
            if (isset($row['score'])) {
                $scores[$row['questionID']] = $row['score'];
            } elseif (isset($row['ffScore'])) {
                $scores[$row['questionID']] = $row['ffScore'];
            }
        }

        addBackendHint("ClientIP.loadContestData:pass");
        addBackendHint(sprintf("Team(%s):loadContestData", escapeHttpValue($teamID)));
        exitWithJson((object)array(
            "success" => true,
            "questionsData" => $questionsData,
            'scores' => $scores,
            "answers" => $answers,
            "isTimed" => ($_SESSION["nbMinutes"] > 0),
            "teamPassword" => $_SESSION["teamPassword"]
        ));
    }



    public function close()
    {
        if (!isset($_SESSION["teamID"]) && !reconnectSession($this->db)) {
            exitWithJsonFailure("Pas de session en cours");
        }
        $teamID = $_SESSION["teamID"];
        $stmtUpdate = $this->db->prepare("UPDATE `team` SET `endTime` = UTC_TIMESTAMP() WHERE `ID` = ? AND `endTime` is NULL");
        $stmtUpdate->execute(array($teamID));
        $_SESSION["closed"] = true;
        $stmt = $this->db->prepare("SELECT `endTime` FROM `team` WHERE `ID` = ?");
        $stmt->execute(array($teamID));
        $row = $stmt->fetchObject();
        addBackendHint("ClientIP.closeContest:pass");
        addBackendHint(sprintf("Team(%s):closeContest", escapeHttpValue($teamID)));
        exitWithJson((object)array("success" => true));
    }
}