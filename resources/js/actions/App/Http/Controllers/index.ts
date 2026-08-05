import TodayController from './TodayController'
import TaskController from './TaskController'
import TaskSectionController from './TaskSectionController'
import CalendarController from './CalendarController'
import EventController from './EventController'
import GoalController from './GoalController'
import AccountController from './AccountController'
import BudgetController from './BudgetController'
import TransactionController from './TransactionController'
import BudgetCategoryController from './BudgetCategoryController'
import RecurringBillController from './RecurringBillController'
import SinkingFundController from './SinkingFundController'
import DebtController from './DebtController'
import ReportController from './ReportController'
import FinanceTrajectoryController from './FinanceTrajectoryController'
import Settings from './Settings'

const Controllers = {
    TodayController: Object.assign(TodayController, TodayController),
    TaskController: Object.assign(TaskController, TaskController),
    TaskSectionController: Object.assign(TaskSectionController, TaskSectionController),
    CalendarController: Object.assign(CalendarController, CalendarController),
    EventController: Object.assign(EventController, EventController),
    GoalController: Object.assign(GoalController, GoalController),
    AccountController: Object.assign(AccountController, AccountController),
    BudgetController: Object.assign(BudgetController, BudgetController),
    TransactionController: Object.assign(TransactionController, TransactionController),
    BudgetCategoryController: Object.assign(BudgetCategoryController, BudgetCategoryController),
    RecurringBillController: Object.assign(RecurringBillController, RecurringBillController),
    SinkingFundController: Object.assign(SinkingFundController, SinkingFundController),
    DebtController: Object.assign(DebtController, DebtController),
    ReportController: Object.assign(ReportController, ReportController),
    FinanceTrajectoryController: Object.assign(FinanceTrajectoryController, FinanceTrajectoryController),
    Settings: Object.assign(Settings, Settings),
}

export default Controllers