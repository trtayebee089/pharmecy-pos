<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Roles;
use App\Models\Biller;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Models\CustomerGroup;
use App\Models\Customer;
use DB;
use Auth;
use Hash;
use Keygen;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use App\Mail\UserDetails;
use Mail;
use App\Models\MailSetting;
use App\Services\CustomerIdentityService;

class UserController extends Controller
{
    use \App\Traits\MailInfo;

    public function __construct(private CustomerIdentityService $identities) {}

    // PHASE 0 HOTFIX: the write endpoints below had no server-side permission
    // checks, so any logged-in user could create an Admin or promote themselves.

    /** Admin (1) and Owner (2) are treated as full administrators, as elsewhere in the app. */
    private function isAdministrator(): bool
    {
        return Auth::check() && Auth::user()->role_id <= 2;
    }

    private function ensurePermission(string $permission): void
    {
        if ($this->isAdministrator()) {
            return;
        }
        $role = Role::find(Auth::user()->role_id);
        abort_unless($role && $role->hasPermissionTo($permission), 403, 'Sorry! You are not allowed to access this module');
    }

    /** Only administrators may grant the Admin/Owner roles or touch Admin/Owner accounts. */
    private function ensureCanManage(?int $targetRoleId, ?User $targetUser = null): void
    {
        if ($this->isAdministrator()) {
            return;
        }
        abort_if($targetRoleId !== null && $targetRoleId <= 2, 403, 'Sorry! You are not allowed to assign this role');
        abort_if($targetUser && $targetUser->role_id <= 2, 403, 'Sorry! You are not allowed to modify this user');
    }

    /** Profile pages: a user may only act on their own account unless administrator. */
    private function ensureSelfOrAdministrator($id): void
    {
        abort_unless((int) $id === (int) Auth::id() || $this->isAdministrator(), 403, 'Sorry! You are not allowed to access this profile');
    }

    public function index()
    {
        $role = Role::find(Auth::user()->role_id);
        if($role->hasPermissionTo('users-index')){
            $permissions = Role::findByName($role->name)->permissions;
            foreach ($permissions as $permission)
                $all_permission[] = $permission->name;
            $lims_user_list = User::where('is_deleted', false)->get();
            $numberOfUserAccount = User::where('is_active', true)->count();
            return view('backend.user.index', compact('lims_user_list', 'all_permission', 'numberOfUserAccount'));
        }
        else
            return redirect()->back()->with('not_permitted', 'Sorry! You are not allowed to access this module');
    }

    public function create()
    {
        $role = Role::find(Auth::user()->role_id);
        if($role->hasPermissionTo('users-add')){
            $lims_role_list = Roles::where('is_active', true)->get();
            $lims_biller_list = Biller::where('is_active', true)->get();
            $lims_supplier_list = Supplier::where('is_active', true)->get();
            $lims_warehouse_list = Warehouse::where('is_active', true)->get();
            $lims_customer_group_list = CustomerGroup::where('is_active', true)->get();
            $numberOfUserAccount = User::where('is_active', true)->count();
            return view('backend.user.create', compact('lims_role_list', 'lims_biller_list', 'lims_supplier_list','lims_warehouse_list', 'lims_customer_group_list', 'numberOfUserAccount'));
        }
        else
            return redirect()->back()->with('not_permitted', 'Sorry! You are not allowed to access this module');
    }

    public function generatePassword()
    {
        $id = Keygen::numeric(6)->generate();
        return $id;
    }

    public function store(Request $request)
    {
        $this->ensurePermission('users-add');
        $this->ensureCanManage($request->filled('role_id') ? (int) $request->role_id : null);
        $this->validate($request, [
            'name' => [
                'max:255',
                    Rule::unique('users')->where(function ($query) {
                    return $query->where('is_deleted', false);
                }),
            ],
            'email' => [
                'email',
                'max:255',
                    Rule::unique('users')->where(function ($query) {
                    return $query->where('is_deleted', false);
                }),
            ],
        ]);

        if($request->role_id == 5) {
            $this->validate($request, [
                'phone_number' => [
                    'max:255',
                        Rule::unique('customers')->where(function ($query) {
                        return $query->where('is_active', 1);
                    }),
                ],
            ]);
        }
        $data = $request->all();
        $message = 'User created successfully';
        $mail_setting = MailSetting::latest()->first();
        if($mail_setting) {
            $this->setMailInfo($mail_setting);
            try {
                Mail::to($data['email'])->send(new UserDetails($data));
            }
            catch(\Exception $e){
                $message = 'User created successfully. Please setup your <a href="setting/mail_setting">mail setting</a> to send mail.';
            }
        }
        if(!isset($data['is_active']))
            $data['is_active'] = false;
        $data['is_deleted'] = false;
        $data['password'] = bcrypt($data['password']);
        $data['phone'] = $data['phone_number'];
        $user_data = User::create($data);
        if($data['role_id'] == 5) {
            $data['user_id'] = $user_data->id;
            $data['name'] = $data['customer_name'];
            $data['phone_number'] = $data['phone'];
            $data['is_active'] = true;
            $this->identities->assertAvailable(
                $this->identities->normalizePhone((string) $data['phone_number']),
                $this->identities->normalizeEmail($data['email'] ?? null),
            );
            $data['password_is_hashed'] = true;
            $this->identities->create($data, 'admin-user');
        }
        return redirect('user')->with('message1', $message);
    }

    public function edit($id)
    {
        $role = Role::find(Auth::user()->role_id);
        if($role->hasPermissionTo('users-edit')){
            $lims_user_data = User::find($id);
            $lims_role_list = Roles::where('is_active', true)->get();
            $lims_biller_list = Biller::where('is_active', true)->get();
            $lims_supplier_list = Supplier::where('is_active', true)->get();
            $lims_warehouse_list = Warehouse::where('is_active', true)->get();
            return view('backend.user.edit', compact('lims_user_data', 'lims_role_list', 'lims_biller_list', 'lims_supplier_list','lims_warehouse_list'));
        }
        else
            return redirect()->back()->with('not_permitted', 'Sorry! You are not allowed to access this module');
    }

    public function update(Request $request, $id)
    {
        $this->ensurePermission('users-edit');
        $this->ensureCanManage(
            $request->filled('role_id') ? (int) $request->role_id : null,
            User::findOrFail($id)
        );
        $this->validate($request, [
            'name' => [
                'max:255',
                Rule::unique('users')->ignore($id)->where(function ($query) {
                    return $query->where('is_deleted', false);
                }),
            ],
            'email' => [
                'email',
                'max:255',
                    Rule::unique('users')->ignore($id)->where(function ($query) {
                    return $query->where('is_deleted', false);
                }),
            ],
        ]);

        $input = $request->except('password');
        if(!isset($input['is_active']))
            $input['is_active'] = false;
        if(!empty($request['password']))
            $input['password'] = bcrypt($request['password']);
        $lims_user_data = User::find($id);
        $lims_user_data->update($input);

        cache()->forget('user_role');
        return redirect('user')->with('message2', 'Data updated successfullly');
    }

    public function superadminProfile($id)
    {
        $lims_user_data = User::find($id);
        return view('landlord.profile', compact('lims_user_data'));
    }

    public function profile($id)
    {
        $this->ensureSelfOrAdministrator($id);
        $lims_user_data = User::find($id);
        return view('backend.user.profile', compact('lims_user_data'));
    }

    public function profileUpdate(Request $request, $id)
    {
        $this->ensureSelfOrAdministrator($id);
        // Only the fields the profile form shows. role_id, is_active, warehouse_id etc.
        // can no longer be injected through this endpoint.
        $input = $request->only(['name', 'email', 'phone', 'company_name']);
        $lims_user_data = User::findOrFail($id);
        $lims_user_data->update($input);
        return redirect()->back()->with('message3', 'Data updated successfullly');
    }

    public function changePassword(Request $request, $id)
    {
        $this->ensureSelfOrAdministrator($id);
        $input = $request->all();
        $lims_user_data = User::find($id);
        if($input['new_pass'] != $input['confirm_pass'])
            return redirect("user/" .  "profile/" . $id )->with('message2', "Please Confirm your new password");

        if (Hash::check($input['current_pass'], $lims_user_data->password)) {
            $lims_user_data->password = bcrypt($input['new_pass']);
            $lims_user_data->save();
        }
        else {
            return redirect("user/" .  "profile/" . $id )->with('message1', "Current Password doesn't match");
        }
        auth()->logout();
        return redirect('/');
    }

    public function deleteBySelection(Request $request)
    {
        $this->ensurePermission('users-delete');
        $user_id = $request['userIdArray'];
        foreach ($user_id as $id) {
            $lims_user_data = User::find($id);
            $this->ensureCanManage(null, $lims_user_data);
            $lims_user_data->is_deleted = true;
            $lims_user_data->is_active = false;
            $lims_user_data->save();
        }
        return 'User deleted successfully!';
    }

    public function destroy($id)
    {
        $this->ensurePermission('users-delete');
        $lims_user_data = User::findOrFail($id);
        $this->ensureCanManage(null, $lims_user_data);
        $lims_user_data->is_deleted = true;
        $lims_user_data->is_active = false;
        $lims_user_data->save();
        if(Auth::id() == $id){
            auth()->logout();
            return redirect('/login');
        }
        else
            return redirect('user')->with('message3', 'Data deleted successfullly');
    }

    public function notificationUsers()
    {
        $notification_users = DB::table('users')->where([
            ['is_active', true],
            ['id', '!=', \Auth::user()->id],
            ['role_id', '!=', '5']
        ])->get();

        $html = '';
        foreach($notification_users as $user){
            $html .='<option value="'.$user->id.'">'.$user->name . ' (' . $user->email. ')'.'</option>';
        }

        return response()->json($html);
    }

    public function allUsers()
    {
        $lims_user_list = DB::table('users')->where('is_active', true)->get();

        $html = '';
        foreach($lims_user_list as $user){
            $html .='<option value="'.$user->id.'">'.$user->name . ' (' . $user->phone. ')'.'</option>';
        }

        return response()->json($html);
    }
}
