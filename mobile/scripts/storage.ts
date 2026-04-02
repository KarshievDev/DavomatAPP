import AsyncStorage from '@react-native-async-storage/async-storage';

export type UserRole = 'employee' | 'manager' | 'admin' | 'superadmin';

export interface Branch {
    id: string;
    name: string;
    latitude: number;
    longitude: number;
    radius: number;
}

export interface Employee {
    id: string;
    fullName: string;
    phone: string;
    email: string;
    password?: string;
    role: UserRole;
    branchId: string;
    position: string;
    hourlyRate: number;
    monthlySalary: number;
    workDays: number;
    workStartTime: string;
    workEndTime: string;
    role_id?: string;
    permissions?: string[];
    balance?: number;
    image?: string;
}

export interface Role {
    id: string;
    name: string;
    permissions: string[];
}

export interface AttendanceRecord {
    id: string;
    employeeId: string;
    type: 'check-in' | 'check-out';
    timestamp: string;
    latitude: number;
    longitude: number;
    branchId: string;
    image?: string;
    hourlyRate?: number;
}

export interface AbsenceRecord {
    id: string;
    employeeId: string;
    startTime: string;
    endTime?: string;
    durationMinutes: number;
    branchId: string;
    status: 'pending' | 'approved' | 'rejected';
}

export interface Payment {
    id: string;
    employeeId: string;
    amount: number;
    type: 'salary' | 'advance';
    paymentDate: string;
    comment: string;
    createdBy?: string;
}

export interface Fine {
    id: string;
    employeeId: string;
    amount: number;
    reason: string;
    date: string;
    status: 'pending' | 'approved' | 'rejected';
}

export interface FineType {
    id: string;
    name: string;
    amount: number;
    description: string;
}

export interface OffDayRequest {
    id: string;
    employeeId: string;
    requestDate: string;
    reason: string;
    status: 'pending' | 'approved' | 'rejected';
    createdAt: string;
}

export interface LatenessWarning {
    id: string;
    employeeId: string;
    reason: string;
    timestamp: string;
}


 
 export interface Task {
     id: string;
     employeeId: string;
     title: string;
     description: string;
     status: 'pending' | 'in_progress' | 'completed' | 'cancelled';
     dueDate: string;
     createdAt: string;
     updatedAt: string;
     employeeName?: string;
 }
 
 import { APP_CONFIG } from '@/constants/Config';

const API_URL = APP_CONFIG.API_URL;

const KEYS = {
    CURRENT_USER: '@current_user_id',
    ACTIVE_ABSENCE: '@active_absence_id'
};

// Simple In-Memory Cache
const cache: Record<string, { data: any, timestamp: number }> = {};
const CACHE_TTL = 30000; // 30 seconds
let isAbsenceRequestInProgress = false;

async function apiCall(action: string, data: any = {}, forceRefresh = false) {
    const cacheKey = action + JSON.stringify(data);

    // Return cached data if available and not expired (only for GET actions)
    if (!forceRefresh && action.startsWith('get_')) {
        const cached = cache[cacheKey];
        if (cached && (Date.now() - cached.timestamp < CACHE_TTL)) {
            return cached.data;
        }
    }

    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 30000); // 30 second timeout

    try {
        const response = await fetch(API_URL, {
            method: 'POST',
            headers: { 
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify({ action, ...data }),
            signal: controller.signal
        });
        clearTimeout(timeoutId);

        const text = await response.text();
        const cleanText = text.trim().replace(/^\uFEFF/, '');
        try {
            const parsed = JSON.parse(cleanText);

            // Cache successful GET responses
            if (action.startsWith('get_') && !parsed.error) {
                cache[cacheKey] = { data: parsed, timestamp: Date.now() };
            }

            // Invalidate relevant cache on writes
            if (action.startsWith('save_') || action.startsWith('update_') || action.startsWith('delete_') || action.startsWith('end_')) {
                Object.keys(cache).forEach(key => {
                    if (key.startsWith('get_')) delete cache[key];
                });
            }

            return parsed;
        } catch (e) {
            console.error('JSON parse error:', cleanText);
            return { error: 'Server javobi xato: ' + cleanText.substring(0, 50) };
        }
    } catch (e: any) {
        console.error('API Error:', e);
        return { error: 'Tarmoq xatosi: ' + (e.message || 'Noma\'lum') };
    }
}

export const StorageService = {
    // Branches
    async getBranches(forceRefresh = false): Promise<Branch[]> {
        const res = await apiCall('get_branches', {}, forceRefresh);
        return Array.isArray(res) ? res.map(b => ({
            ...b,
            id: String(b.id),
            latitude: parseFloat(b.latitude),
            longitude: parseFloat(b.longitude),
            radius: parseInt(b.radius)
        })) : [];
    },
    async saveBranch(branch: Branch) {
        await apiCall('save_branch', branch);
    },
    async updateBranch(updated: Branch) {
        await apiCall('update_branch', updated);
    },
    async deleteBranch(id: string) {
        await apiCall('delete_branch', { id });
    },

    // Employees
    async getEmployees(forceRefresh = false): Promise<Employee[]> {
        const res = await apiCall('get_employees', {}, forceRefresh);
        return Array.isArray(res) ? res.map(e => ({
            ...e,
            id: String(e.id),
            fullName: e.full_name,
            branchId: String(e.branch_id),
            hourlyRate: parseFloat(e.hourly_rate),
            monthlySalary: parseFloat(e.monthly_salary || 0),
            workDays: parseInt(e.work_days_per_month || 26),
            workStartTime: e.work_start_time || '09:00:00',
            workEndTime: e.work_end_time || '18:00:00',
            role_id: String(e.role_id || ''),
            permissions: e.permissions || [],
            image: e.image_url
        })) : [];
    },
    async saveEmployee(employee: Employee) {
        await apiCall('save_employee', {
            fullName: employee.fullName,
            phone: employee.phone,
            email: employee.email,
            role: employee.role,
            role_id: employee.role_id,
            branchId: employee.branchId,
            position: employee.position,
            hourlyRate: employee.hourlyRate,
            monthlySalary: employee.monthlySalary,
            workDays: employee.workDays,
            workStartTime: employee.workStartTime,
            workEndTime: employee.workEndTime
        });
    },
    async updateEmployee(updated: Employee) {
        await apiCall('update_employee', updated);
    },
    async deleteEmployee(id: string) {
        await apiCall('delete_employee', { id });
    },

    // Attendance
    async getRecords(forceRefresh = false): Promise<AttendanceRecord[]> {
        const res = await apiCall('get_records', {}, forceRefresh);
        return Array.isArray(res) ? res.map(r => ({
            ...r,
            id: String(r.id),
            employeeId: String(r.employee_id),
            branchId: String(r.branch_id),
            latitude: parseFloat(r.latitude),
            longitude: parseFloat(r.longitude),
            image: r.image_url,
            hourlyRate: parseFloat(r.hourly_rate || 0)
        })) : [];
    },
    async getMonthlyStats(employeeId: string, forceRefresh = false): Promise<any> {
        return await apiCall('get_employee_month_stats', { employeeId }, forceRefresh);
    },
    async saveRecord(record: AttendanceRecord): Promise<any> {
        return await apiCall('save_record', record);
    },

    // Auth
    async login(email: string, password: string): Promise<Employee | { error: string } | null> {
        const res = await apiCall('login', { email, password });
        if (res && res.error) {
            return { error: res.error };
        }
        if (res && res.id) {
            const user: Employee = {
                ...res,
                id: String(res.id),
                fullName: res.full_name,
                branchId: String(res.branch_id),
                hourlyRate: parseFloat(res.hourly_rate),
                monthlySalary: parseFloat(res.monthly_salary || 0),
                workDays: parseInt(res.work_days_per_month || 26),
                workStartTime: res.work_start_time || '09:00:00',
                workEndTime: res.work_end_time || '18:00:00',
                role_id: String(res.role_id || ''),
                permissions: res.permissions || [],
                image: res.image_url
            };
            await this.setCurrentUser(user.id);
            return user;
        }
        return null;
    },
    async setCurrentUser(employeeId: string) {
        await AsyncStorage.setItem(KEYS.CURRENT_USER, employeeId);
    },
    async getCurrentUser(): Promise<string | null> {
        return await AsyncStorage.getItem(KEYS.CURRENT_USER);
    },
    async logout() {
        await AsyncStorage.removeItem(KEYS.CURRENT_USER);
    },
    async updatePassword(employeeId: string, newPass: string) {
        const res = await apiCall('update_password', { employeeId, newPass });
        return res.success;
    },
    async updateProfileImage(employeeId: string, image: string) {
        return await apiCall('update_profile_image', { employeeId, image });
    },
    async savePushToken(employeeId: string, token: string) {
        return await apiCall('save_push_token', { employeeId, token });
    },
    async updateAttendanceTime(attendanceId: string, newTime: string, adminId: string, reason: string) {
        return await apiCall('update_attendance_time', { attendanceId, newTime, adminId, reason });
    },
    async getAttendanceLogs() {
        return await apiCall('get_attendance_logs', {});
    },

    // Absence tracking
    async getAbsences(forceRefresh = false): Promise<AbsenceRecord[]> {
        const res = await apiCall('get_absences', {}, forceRefresh);
        return Array.isArray(res) ? res.map(a => ({
            ...a,
            id: String(a.id),
            employeeId: String(a.employee_id),
            branchId: String(a.branch_id),
            startTime: a.start_time,
            endTime: a.end_time,
            durationMinutes: parseInt(a.duration_minutes || 0)
        })) : [];
    },
    async startAbsence(employeeId: string, branchId: string) {
        if (isAbsenceRequestInProgress) return;
        const activeId = await AsyncStorage.getItem(KEYS.ACTIVE_ABSENCE);
        if (activeId) return;

        isAbsenceRequestInProgress = true;
        try {
            const res = await apiCall('save_absence', { employeeId, branchId });
            if (res && res.id) {
                await AsyncStorage.setItem(KEYS.ACTIVE_ABSENCE, res.id.toString());
            }
        } finally {
            isAbsenceRequestInProgress = false;
        }
    },
    async endAbsence(branchId?: string) {
        const activeId = await AsyncStorage.getItem(KEYS.ACTIVE_ABSENCE);
        if (!activeId) return;

        // endTime/duration will be set by the server
        const res = await apiCall('end_absence', { id: activeId, branch_id: branchId });
        if (res.success) {
            await AsyncStorage.removeItem(KEYS.ACTIVE_ABSENCE);
        }
    },
    async updateAbsenceStatus(id: string, status: 'approved' | 'rejected') {
        const res = await apiCall('update_absence_status', { id, status });
        return res.success;
    },

    // Payments (Advances)
    async getPayments(forceRefresh = false): Promise<Payment[]> {
        const res = await apiCall('get_payments', {}, forceRefresh);
        return Array.isArray(res) ? res.map(p => ({
            ...p,
            id: String(p.id),
            employeeId: String(p.employee_id),
            amount: parseFloat(p.amount),
            paymentDate: p.payment_date,
            createdBy: String(p.created_by)
        })) : [];
    },
    async savePayment(payment: Partial<Payment>) {
        return await apiCall('save_payment', {
            employeeId: payment.employeeId,
            amount: payment.amount,
            type: payment.type || 'advance',
            comment: payment.comment,
            createdBy: payment.createdBy
        });
    },

    // Fines
    async getFines(forceRefresh = false): Promise<Fine[]> {
        const res = await apiCall('get_fines', {}, forceRefresh);
        return Array.isArray(res) ? res.map(f => ({
            ...f,
            id: String(f.id),
            employeeId: String(f.employee_id),
            amount: parseFloat(f.amount),
            reason: f.reason,
            date: f.date,
            status: f.status || 'pending'
        })) : [];
    },
    async updateFineStatus(id: string, status: 'approved' | 'rejected') {
        const res = await apiCall('update_fine_status', { id, status });
        return res.success;
    },

    // Fine Types
    async getFineTypes(forceRefresh = false): Promise<FineType[]> {
        const res = await apiCall('get_fine_types', {}, forceRefresh);
        return Array.isArray(res) ? res.map(ft => ({
            ...ft,
            id: String(ft.id),
            amount: parseFloat(ft.amount)
        })) : [];
    },
    async saveFineType(name: string, amount: number, description: string) {
        return await apiCall('save_fine_type', { name, amount, description });
    },
    async updateFineType(id: string, name: string, amount: number, description: string) {
        return await apiCall('update_fine_type', { id, name, amount, description });
    },
    async deleteFineType(id: string) {
        return await apiCall('delete_fine_type', { id });
    },

    // Off Day Requests
    async getOffDayRequests(forceRefresh = false): Promise<OffDayRequest[]> {
        const res = await apiCall('get_off_day_requests', {}, forceRefresh);
        return Array.isArray(res) ? res.map(r => ({
            ...r,
            id: String(r.id),
            employeeId: String(r.employee_id),
            requestDate: r.request_date,
            reason: r.reason,
            createdAt: r.created_at
        })) : [];
    },
    async saveOffDayRequest(request: Partial<OffDayRequest>) {
        return await apiCall('save_off_day_request', request);
    },
    async updateOffDayRequestStatus(id: string, status: 'approved' | 'rejected') {
        const res = await apiCall('update_off_day_request_status', { id, status });
        return res.success;
    },
    async getOffdayQuota(employeeId: string) {
        return await apiCall('get_offday_quota', { employeeId });
    },

    // Warnings
    async getWarnings(forceRefresh = false): Promise<LatenessWarning[]> {
        const res = await apiCall('get_warnings', {}, forceRefresh);
        return Array.isArray(res) ? res.map(w => ({
            ...w,
            id: String(w.id),
            employeeId: String(w.employee_id)
        })) : [];
    },

    // Tasks
    async getTasks(employeeId: string, forceRefresh = false): Promise<Task[]> {
        const res = await apiCall('get_tasks', { employeeId }, forceRefresh);
        return Array.isArray(res) ? res.map(t => ({
            ...t,
            id: String(t.id),
            employeeId: String(t.employee_id),
            dueDate: t.due_date,
            createdAt: t.created_at,
            updatedAt: t.updated_at
        })) : [];
    },
    async updateTaskStatus(taskId: string, status: string) {
        return await apiCall('update_task_status', { taskId, status });
    },

    // Roles
    async getRoles(forceRefresh = false): Promise<Role[]> {
        const res = await apiCall('get_roles', {}, forceRefresh);
        return Array.isArray(res) ? res.map(r => ({
            ...r,
            id: String(r.id)
        })) : [];
    },
    async saveRole(role: Partial<Role>) {
        return await apiCall('save_role', role);
    },
    async updateRole(role: Role) {
        return await apiCall('update_role', role);
    },
    async deleteRole(id: string) {
        return await apiCall('delete_role', { id });
    },

    // Global Data Fetch
    async getAllData(forceRefresh = false): Promise<{
        branches: Branch[],
        employees: Employee[],
        records: AttendanceRecord[],
        absences: AbsenceRecord[],
        payments: Payment[],
        offDayRequests: OffDayRequest[],
        roles: Role[],
        fines: Fine[],
        fineTypes: FineType[],
        warnings: LatenessWarning[],
        tasks: Task[]
    }> {
        const res = await apiCall('get_all_data', {}, forceRefresh);
        if (!res || res.error) return { branches: [], employees: [], records: [], absences: [], payments: [], offDayRequests: [], roles: [], fines: [], fineTypes: [], warnings: [], tasks: [] };

        return {
            branches: (res.branches || []).map((b: any) => ({
                ...b,
                id: String(b.id),
                latitude: parseFloat(b.latitude),
                longitude: parseFloat(b.longitude),
                radius: parseInt(b.radius)
            })),
            employees: (res.employees || []).map((e: any) => ({
                ...e,
                id: String(e.id),
                fullName: e.full_name,
                branchId: String(e.branch_id),
                hourlyRate: parseFloat(e.hourly_rate),
                monthlySalary: parseFloat(e.monthly_salary || 0),
                workDays: parseInt(e.work_days_per_month || 26),
                workStartTime: e.work_start_time || '09:00:00',
                workEndTime: e.work_end_time || '18:00:00',
                role_id: String(e.role_id || ''),
                permissions: e.permissions || []
            })),
            records: (res.records || []).map((r: any) => ({
                ...r,
                id: String(r.id),
                employeeId: String(r.employee_id),
                branchId: String(r.branch_id),
                latitude: parseFloat(r.latitude),
                longitude: parseFloat(r.longitude),
                image: r.image_url
            })),
            absences: (res.absences || []).map((a: any) => ({
                ...a,
                id: String(a.id),
                employeeId: String(a.employee_id),
                branchId: String(a.branch_id),
                startTime: a.start_time,
                endTime: a.end_time,
                durationMinutes: parseInt(a.duration_minutes || 0)
            })),
            payments: (res.payments || []).map((p: any) => ({
                ...p,
                id: String(p.id),
                employeeId: String(p.employee_id),
                amount: parseFloat(p.amount),
                paymentDate: p.payment_date,
                createdBy: String(p.created_by)
            })),
            offDayRequests: (res.offDayRequests || []).map((r: any) => ({
                ...r,
                id: String(r.id),
                employeeId: String(r.employee_id),
                requestDate: r.request_date,
                reason: r.reason,
                createdAt: r.created_at
            })),
            roles: (res.roles || []).map((r: any) => ({
                ...r,
                id: String(r.id)
            })),
            fines: (res.fines || []).map((f: any) => ({
                ...f,
                id: String(f.id),
                employeeId: String(f.employee_id),
                amount: parseFloat(f.amount),
                reason: f.reason,
                date: f.date,
                status: f.status || 'pending'
            })),
            fineTypes: (res.fineTypes || []).map((ft: any) => ({
                ...ft,
                id: String(ft.id),
                amount: parseFloat(ft.amount)
            })),
            warnings: (res.warnings || []).map((w: any) => ({
                ...w,
                id: String(w.id),
                employeeId: String(w.employee_id)
            })),
            tasks: (res.tasks || []).map((t: any) => ({
                ...t,
                id: String(t.id),
                employeeId: String(t.employee_id),
                dueDate: t.due_date,
                createdAt: t.created_at,
                updatedAt: t.updated_at
            }))
        };
    }
};
