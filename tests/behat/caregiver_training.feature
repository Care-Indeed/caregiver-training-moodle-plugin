@local @local_caregivertraining
Feature: Caregiver annual training learner experience and administrator view
  In order to complete annual training on time and keep evidence
  As a caregiver and as an administrator
  I need a due-date banner, a next activity action and a protected administrator view

  Background:
    Given the synthetic caregiver annual course exists
    And caregiver "cg1" has cycle "BEHAT-CG1-2026" opening 0 days from today and due 30 days from today
    And caregiver "cg2" has cycle "BEHAT-CG2-2026" opening -60 days from today and due -1 days from today

  Scenario: Learner sees the due date, unresolved time policy and a next activity action
    Given I log in as "cg1"
    And I am on "Synthetic annual caregiver training" course homepage
    Then I should see "30 days remaining"
    And I should see "completion is on hold"
    And I click on "Next activity" "link"
    Then I should see "Lesson"

  Scenario: Overdue learner keeps access
    Given I log in as "cg2"
    And I am on "Synthetic annual caregiver training" course homepage
    Then I should see "Overdue by 1 day(s). The course remains open."

  Scenario: Learners only see their own cycle
    Given I log in as "cg1"
    And I am on "Synthetic annual caregiver training" course homepage
    Then I should not see "Overdue by"
    And I should not see "BEHAT-CG2-2026"
    And I should not see "Caregiver training" in the ".secondary-navigation" "css_element"

  Scenario: Administrator reviews current cycles and snapshots
    Given I log in as "admin"
    When I visit "/local/caregivertraining/index.php?tab=cycles"
    Then I should see "BEHAT-CG1-2026"
    And I should see "Overdue (access open)"
    And "Export CSV" "button" should exist
    When I visit "/local/caregivertraining/index.php?tab=blocked"
    Then I should see "Nothing to display."
