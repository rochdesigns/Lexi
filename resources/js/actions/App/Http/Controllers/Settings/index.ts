import ProfileController from './ProfileController'
import SecurityController from './SecurityController'
import BudgetCycleController from './BudgetCycleController'

const Settings = {
    ProfileController: Object.assign(ProfileController, ProfileController),
    SecurityController: Object.assign(SecurityController, SecurityController),
    BudgetCycleController: Object.assign(BudgetCycleController, BudgetCycleController),
}

export default Settings