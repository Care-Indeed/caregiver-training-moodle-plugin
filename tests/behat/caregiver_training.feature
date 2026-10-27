@local @local_caregivertraining
Feature: Caregiver annual training learner experience and administrator view
  In order to complete annual training on time and keep evidence
  As a caregiver and as an administrator
  I need a due-date banner, a next activity action and a protected administrator view

  Background:
    Given the synthetic caregiver annual course exists
    And caregiver "cg1" has cycle "BEHAT-CG1-2026" due 30 days from today
    And caregiver "cg2" has cycle "BEHAT-CG2-2026" due -1 days from today

  Scenario: Learner sees the due date, unresolved time policy and a next activity action
    Given I log in as "cg1"
    And I am on "Synthetic annual caregiver training" course homepage
    Then I should see "30 days remaining"
    And I should see "completion is on hold"
    And "Next activity" "link" should not exist
    When I am on the first annual training activity
    And I click on "Next activity" "link"
    Then I should see "Quiz"

  Scenario: With no minimum time the banner shows only the due date
    Given the following config values are set as admin:
      | timepolicy      | tracked | local_caregivertraining |
      | requiredseconds | 0       | local_caregivertraining |
    And I log in as "cg1"
    And I am on "Synthetic annual caregiver training" course homepage
    Then I should see "30 days remaining"
    And I should not see "completed of"
    And I should not see "completion is on hold"
    And ".local-cgt-pie" "css_element" should not exist

  Scenario: The last activity shows no next activity action
    Given caregiver "cg1" has completed every annual training activity
    And I log in as "cg1"
    When I am on the last annual training activity
    Then "Next activity" "link" should not exist
    And I should not see "All available activities are complete."
    And I should not see "Complete this activity to unlock the next one."

  Scenario: The last activity shows no next activity action while earlier ones are unfinished
    Given caregiver "cg1" has completed the last annual training activity
    And I log in as "cg1"
    When I am on the last annual training activity
    Then "Next activity" "link" should not exist

  Scenario: Overdue learner keeps access for 14 days after the due date
    Given I log in as "cg2"
    And I am on "Synthetic annual caregiver training" course homepage
    Then I should see "Overdue by 1 day(s). The course remains open until"

  Scenario: Learners only see their own cycle
    Given I log in as "cg1"
    And I am on "Synthetic annual caregiver training" course homepage
    Then I should not see "Overdue by"
    And I should not see "BEHAT-CG2-2026"
    And I should not see "Training records" in the ".secondary-navigation" "css_element"

  Scenario: Administrator finds an employee and opens their training history
    Given I log in as "admin"
    When I navigate to "Plugins > Local plugins > Caregiver annual training > Training records" in site administration
    Then I should see "BEHAT-CG1-2026"
    And I should see "Overdue"
    And "Download" "button" should exist
    When I set the field "Search by name, AlayaCare ID, payroll or HCA number, Cycle ID or certificate code" to "BEHAT-CG2"
    And I press "Apply"
    Then I should see "BEHAT-CG2-2026"
    And I should not see "BEHAT-CG1-2026"
    When I set the field "Status" to "Open"
    And I press "Apply"
    Then I should see "No employees match your search."
    When I follow "Clear filters"
    And I follow "Synthetic cg1"
    Then I should see "Training history"
    And I should see "BEHAT-CG1-2026"
    And I should see "View profile"
    When I follow "Back"
    Then I should see "BEHAT-CG2-2026"

  @javascript
  Scenario: Choosing another annual course updates the certificate list before saving
    Given the following "courses" exist:
      | fullname          | shortname | enablecompletion |
      | Other annual year | OTHERYEAR | 1                |
    And the following "activities" exist:
      | activity   | name                    | course    |
      | customcert | Other year certificate  | OTHERYEAR |
    And I log in as "admin"
    And I navigate to "Plugins > Local plugins > Caregiver annual training > General settings" in site administration
    When I set the field "Annual training course" to "Other annual year"
    Then the field "Certificate activity" matches value "Other year certificate"
    And I press "Save changes"
    And the field "Certificate activity" matches value "Other year certificate"

  Scenario: Administrator checks blocked resets and exceptions
    Given I log in as "admin"
    When I visit "/local/caregivertraining/index.php?view=blocked"
    Then I should see "No blocked resets."
    When I visit "/local/caregivertraining/index.php?view=exceptions"
    Then I should see "No open exceptions."
    And I should see "Show resolved too"
