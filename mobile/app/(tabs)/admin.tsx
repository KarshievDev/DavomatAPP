import { AbsenceRecord, AttendanceRecord, Branch, Employee, Payment, StorageService, UserRole, Fine, FineType, LatenessWarning } from '@/scripts/storage';
import { formatSafe, parseAnyDate, getTimeMinutes } from '@/scripts/dateUtils';
import { format, getDaysInMonth, parseISO } from 'date-fns';
import { Ban, Banknote, Check, ClipboardList, Clock, Edit, Key, LogOut, MapPin, Menu, Plus, Shield, Trash2, Users, X, Calendar, CreditCard, History, Settings, ArrowRight, AlertTriangle } from 'lucide-react-native';
import React, { useEffect, useState } from 'react';
import { ActivityIndicator, Alert, FlatList, Image, Modal, ScrollView, StyleSheet, Text, TextInput, TouchableOpacity, View, KeyboardAvoidingView, Platform, Keyboard, TouchableWithoutFeedback } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

type AdminTab = 'reporting' | 'absences' | 'employees' | 'branches' | 'payments' | 'offdays' | 'roles' | 'logs' | 'fines' | 'fine_types' | 'warnings';

export default function AdminScreen() {
    const insets = useSafeAreaInsets();
    const [activeTab, setActiveTab] = useState<AdminTab>('reporting');
    const [branches, setBranches] = useState<Branch[]>([]);
    const [employees, setEmployees] = useState<Employee[]>([]);
    const [records, setRecords] = useState<AttendanceRecord[]>([]);
    const [absences, setAbsences] = useState<AbsenceRecord[]>([]);
    const [payments, setPayments] = useState<Payment[]>([]);
    const [offDayRequests, setOffDayRequests] = useState<any[]>([]);
    const [rolesList, setRolesList] = useState<any[]>([]);
    const [editLogs, setEditLogs] = useState<any[]>([]);
    const [fines, setFines] = useState<Fine[]>([]);
    const [fineTypes, setFineTypes] = useState<FineType[]>([]);
    const [warnings, setWarnings] = useState<LatenessWarning[]>([]);
    const [currentUser, setCurrentUser] = useState<Employee | null>(null);
    const [isMenuOpen, setIsMenuOpen] = useState(false);
    const [loading, setLoading] = useState(true);

    // Filters
    const [filterBranchId, setFilterBranchId] = useState<string>('');
    const [filterDate, setFilterDate] = useState<string>(''); // YYYY-MM-DD
    const [filterName, setFilterName] = useState<string>('');
    
    // Selected Employee for Detail Modal
    const [selectedEmployee, setSelectedEmployee] = useState<Employee | null>(null);
    const [showEmployeeDetail, setShowEmployeeDetail] = useState(false);
    
    // Attendance Edit form
    const [showEditAtt, setShowEditAtt] = useState(false);
    const [editingAtt, setEditingAtt] = useState<{id: string, time: string, type: string, recordId: string} | null>(null);
    const [newAttTime, setNewAttTime] = useState('');
    const [editReason, setEditReason] = useState('');

    // Form states
    const [showForm, setShowForm] = useState(false);
    const [editingId, setEditingId] = useState<string | null>(null);


    const [branchName, setBranchName] = useState('');
    const [lat, setLat] = useState('');
    const [lon, setLon] = useState('');
    const [radius, setRadius] = useState('200');

    const [empName, setEmpName] = useState('');
    const [empPhone, setEmpPhone] = useState('');
    const [empEmail, setEmpEmail] = useState('');
    const [empPos, setEmpPos] = useState('');
    const [empRate, setEmpRate] = useState('');
    const [empBranchId, setEmpBranchId] = useState('');
    const [empRole, setEmpRole] = useState<UserRole>('employee');
    const [empStartTime, setEmpStartTime] = useState('09:00');
    const [empEndTime, setEmpEndTime] = useState('18:00');
    const [empSalary, setEmpSalary] = useState('');
    const [empOffDays, setEmpOffDays] = useState('4');

    // Payment form
    const [payEmpId, setPayEmpId] = useState('');
    const [payAmount, setPayAmount] = useState('');
    const [payType, setPayType] = useState<'salary' | 'advance'>('advance');
    const [payComment, setPayComment] = useState('');

    // Role form
    const [roleName, setRoleName] = useState('');
    const [rolePerms, setRolePerms] = useState<string[]>([]);
    const [empRoleId, setEmpRoleId] = useState('');

    // Fine Type form
    const [ftName, setFtName] = useState('');
    const [ftAmount, setFtAmount] = useState('');
    const [ftDesc, setFtDesc] = useState('');

    const PERMISSIONS = [
        { id: 'reporting', label: 'Hisobotlar' },
        { id: 'absences', label: 'Uzoqlashishlar' },
        { id: 'employees', label: 'Xodimlar' },
        { id: 'branches', label: 'Filiallar' },
        { id: 'payments', label: 'To\'lovlar' },
        { id: 'offdays', label: 'Dam olish' },
        { id: 'fines', label: 'Jarimalar' },
        { id: 'fine_types', label: 'Jarima turlari' },
        { id: 'warnings', label: 'Kechikishlar' }
    ];

    const hasPermission = (tab: AdminTab) => {
        if (!currentUser) return false;
        if (currentUser.role === 'superadmin') return true;
        if (tab === 'reporting' || tab === 'fine_types') return true; 
        if (currentUser.role === 'admin') return true;
        
        // Check custom role permissions if assigned
        if (currentUser.role_id && rolesList.length > 0) {
            const myRole = rolesList.find(r => r.id === currentUser.role_id);
            if (myRole && myRole.permissions?.includes(tab)) return true;
        }

        return currentUser.permissions?.includes(tab) || false;
    };

    useEffect(() => {
        loadData();
    }, [activeTab]);

    const loadData = async () => {
        setLoading(true);
        try {
            const userId = await StorageService.getCurrentUser();
            const data = await StorageService.getAllData();

            const me = (data.employees || []).find(emp => emp.id === userId);
            setCurrentUser(me || null);
            setBranches(Array.isArray(data.branches) ? data.branches : []);
            setEmployees(Array.isArray(data.employees) ? data.employees : []);
            setRecords(Array.isArray(data.records) ? [...data.records].reverse() : []);
            setAbsences(Array.isArray(data.absences) ? [...data.absences].reverse() : []);
            setPayments(Array.isArray(data.payments) ? data.payments : []);
            setOffDayRequests(Array.isArray(data.offDayRequests) ? [...data.offDayRequests].reverse() : []);
            setRolesList(Array.isArray(data.roles) ? data.roles : []);
            setFines(Array.isArray(data.fines) ? [...data.fines].reverse() : []);
            setFineTypes(Array.isArray(data.fineTypes) ? data.fineTypes : []);
            setWarnings(Array.isArray(data.warnings) ? data.warnings : []);

            if (me?.role === 'superadmin') {
                const logs = await StorageService.getAttendanceLogs();
                setEditLogs(Array.isArray(logs) ? logs : []);
            }
        } catch (e) {
            console.error('Load data error:', e);
        } finally {
            setLoading(false);
        }
    };

    const handleSaveAttTime = async () => {
        if (!editingAtt || !newAttTime || !currentUser || !editingAtt.time) return;
        
        try {
            // Need the full date part from the record
            const oldDateStr = editingAtt.time.split(' ')[0];
            const fullNewTimestamp = `${oldDateStr} ${newAttTime}:00`;
            
            await StorageService.updateAttendanceTime(
                editingAtt.recordId,
                fullNewTimestamp,
                currentUser.id,
                editReason
            );
            
            setShowEditAtt(false);
            setEditingAtt(null);
            setEditReason('');
            loadData();
            Alert.alert('Muvaffaqiyatli', 'Vaqt o\'zgartirildi');
        } catch (e) {
            Alert.alert('Xato', 'O\'zgartirishda xatolik yuz berdi');
        }
    };

    const handleLogout = async () => {
        Alert.alert('Chiqish', 'Tizimdan chiqmoqchimisiz?', [
            { text: 'Yo\'q', style: 'cancel' },
            {
                text: 'Ha', style: 'destructive', onPress: async () => {
                    await StorageService.logout();
                }
            }
        ]);
    };

    const resetForm = () => {
        setShowForm(false);
        setEditingId(null);
        setBranchName(''); setLat(''); setLon(''); setRadius('200');
        setEmpName(''); setEmpPhone(''); setEmpEmail(''); setEmpPos(''); setEmpRate(''); setEmpBranchId('');
        setEmpStartTime('09:00'); setEmpEndTime('18:00');
        setEmpSalary(''); setEmpOffDays('4'); setEmpRoleId('');
        setPayEmpId(''); setPayAmount(''); setPayType('advance'); setPayComment('');
        setRoleName(''); setRolePerms([]);
        setFtName(''); setFtAmount(''); setFtDesc('');
    };

    const handleSaveFineType = async () => {
        if (!ftName || !ftAmount) return Alert.alert('Xato', 'Nomi va summani kiriting');
        const amount = parseFloat(ftAmount);
        if (editingId) {
            await StorageService.updateFineType(editingId, ftName, amount, ftDesc);
        } else {
            await StorageService.saveFineType(ftName, amount, ftDesc);
        }
        resetForm();
        loadData();
    };

    const handleEditFineType = (ft: FineType) => {
        setEditingId(ft.id);
        setFtName(ft.name);
        setFtAmount(ft.amount.toString());
        setFtDesc(ft.description || '');
        setShowForm(true);
    };

    const handleDeleteFineType = (id: string) => {
        Alert.alert('O\'chirish', 'Ushbu jarima turini o\'chirmoqchimisiz?', [
            { text: 'Yo\'q', style: 'cancel' },
            {
                text: 'Ha, o\'chir', style: 'destructive', onPress: async () => {
                    await StorageService.deleteFineType(id);
                    loadData();
                }
            }
        ]);
    };

    const handleSaveBranch = async () => {
        if (!branchName || !lat || !lon || !radius) return Alert.alert('Xato', 'Barcha maydonlarni to\'ldiring');
        const branchData: Branch = {
            id: editingId || Date.now().toString(),
            name: branchName,
            latitude: parseFloat(lat),
            longitude: parseFloat(lon),
            radius: parseInt(radius),
        };

        if (editingId) {
            await StorageService.updateBranch(branchData);
        } else {
            await StorageService.saveBranch(branchData);
        }
        resetForm();
        loadData();
    };

    const handleDeleteBranch = (id: string) => {
        Alert.alert('O\'chirish', 'Ushbu filialni o\'chirmoqchimisiz?', [
            { text: 'Yo\'q', style: 'cancel' },
            {
                text: 'Ha, o\'chir', style: 'destructive', onPress: async () => {
                    await StorageService.deleteBranch(id);
                    loadData();
                }
            }
        ]);
    };

    const handleEditBranch = (br: Branch) => {
        setEditingId(br.id);
        setBranchName(br.name);
        setLat(br.latitude.toString());
        setLon(br.longitude.toString());
        setRadius(br.radius.toString());
        setShowForm(true);
    };

    const handleSaveEmployee = async () => {
        if (!empName || !empPhone || !empEmail || !empBranchId) {
            return Alert.alert('Xato', 'Barcha zaruriy maydonlarni to\'ldiring');
        }

        const daysInMonth = getDaysInMonth(new Date());
        const workDays = daysInMonth - (parseInt(empOffDays) || 0);
        
        const workStartMins = getTimeMinutes(empStartTime);
        let workEndMins = getTimeMinutes(empEndTime);
        if (workEndMins <= workStartMins) {
            workEndMins += 24 * 60; // Add 24 hours if end time is before or same as start time
        }
        const dailyHours = (workEndMins - workStartMins) / 60;
        
        const monthlySalary = parseFloat(empSalary) || 0;
        const hourlyRate = dailyHours > 0 && workDays > 0 ? (monthlySalary / (workDays * dailyHours)) : 0;

        const employeeData: Employee = {
            id: editingId || Date.now().toString(),
            fullName: empName,
            phone: empPhone,
            email: empEmail,
            role: empRole,
            branchId: empBranchId,
            position: empPos,
            hourlyRate: hourlyRate, 
            monthlySalary: monthlySalary,
            workDays: workDays,
            workStartTime: empStartTime + ':00',
            workEndTime: empEndTime + ':00',
            role_id: empRoleId
        };

        if (editingId) {
            await StorageService.updateEmployee(employeeData);
        } else {
            await StorageService.saveEmployee(employeeData);
        }
        resetForm();
        loadData();
        Alert.alert('Muvaffaqiyatli', editingId ? 'Ma\'lumotlar yangilandi' : 'Xodim qo\'shildi');
    };

    const handleResetPassword = (empId: string) => {
        Alert.alert('Parolni yangilash', 'Ushbu xodim parolini "12345678" ga qaytarmoqchimisiz?', [
            { text: 'Yo\'q', style: 'cancel' },
            {
                text: 'Ha, yangila', onPress: async () => {
                    await StorageService.updatePassword(empId, '12345678');
                    Alert.alert('Muvaffaqiyatli', 'Parol "12345678" ga almashtirildi');
                }
            }
        ]);
    };

    const handleDeleteEmployee = (id: string) => {
        Alert.alert('O\'chirish', 'Ushbu xodimni o\'chirmoqchimisiz?', [
            { text: 'Yo\'q', style: 'cancel' },
            {
                text: 'Ha, o\'chir', style: 'destructive', onPress: async () => {
                    await StorageService.deleteEmployee(id);
                    loadData();
                }
            }
        ]);
    };

    const handleEditEmployee = (emp: Employee) => {
        setEditingId(emp.id);
        setEmpName(emp.fullName);
        setEmpPhone(emp.phone);
        setEmpEmail(emp.email);
        setEmpPos(emp.position);
        setEmpRate(emp.hourlyRate.toString());
        setEmpBranchId(emp.branchId);
        setEmpRole(emp.role);
        setEmpStartTime((emp.workStartTime || '09:00:00').substring(0, 5));
        setEmpEndTime((emp.workEndTime || '18:00:00').substring(0, 5));
        setEmpSalary(emp.monthlySalary?.toString() || '');
        const daysInMonth = getDaysInMonth(new Date());
        const offDays = daysInMonth - (emp.workDays || 26);
        setEmpOffDays(offDays.toString());
        setEmpRoleId(emp.role_id || '');
        setShowForm(true);
    };

    const updateAbsenceStatus = async (id: string, status: 'approved' | 'rejected') => {
        await StorageService.updateAbsenceStatus(id, status);
        loadData();
    };

    const roles: UserRole[] = ['employee', 'manager', 'admin', 'superadmin'];
    const roleLabels: Record<UserRole, string> = {
        'employee': 'Xodim',
        'manager': 'Menejer',
        'admin': 'Admin',
        'superadmin': 'Superadmin'
    };

    const formatMins = (mins: number) => {
        if (isNaN(mins)) return '0:00';
        const safeMins = Math.abs(mins);
        const h = Math.floor(safeMins / 60);
        const m = Math.floor(safeMins % 60);
        const mStr = m < 10 ? '0' + m : m;
        return `${h}:${mStr}`;
    };

    const sessions = React.useMemo(() => {
        if (!currentUser) return [];

        const recordsByEmp: Record<string, AttendanceRecord[]> = {};
        records.forEach(r => {
            const canSee = currentUser.role === 'superadmin' || currentUser.role === 'admin' ||
                (currentUser.role === 'manager' && r.branchId === currentUser.branchId);
            if (canSee) {
                if (!recordsByEmp[r.employeeId]) recordsByEmp[r.employeeId] = [];
                recordsByEmp[r.employeeId].push(r);
            }
        });

        const absByEmp: Record<string, AbsenceRecord[]> = {};
        absences.forEach(a => {
            const canSee = currentUser.role === 'superadmin' || currentUser.role === 'admin' ||
                (currentUser.role === 'manager' && a.branchId === currentUser.branchId);
            if (canSee) {
                if (!absByEmp[a.employeeId]) absByEmp[a.employeeId] = [];
                absByEmp[a.employeeId].push(a);
            }
        });

        const result: any[] = [];
        const viewableEmployees = employees.filter(e => {
            if (currentUser.role === 'superadmin' || currentUser.role === 'admin') return true;
            if (currentUser.role === 'manager') return e.branchId === currentUser.branchId;
            return e.id === currentUser.id;
        });

        viewableEmployees.forEach(emp => {
            const empRecs = (recordsByEmp[emp.id] || []).sort((a, b) => (a.timestamp || '').localeCompare(b.timestamp || ''));
            let currentCheckIn: AttendanceRecord | null = null;

            empRecs.forEach(r => {
                if (r.type === 'check-in') {
                    currentCheckIn = r;
                } else if (r.type === 'check-out' && currentCheckIn) {
                    processAdminSession(emp, currentCheckIn, r, absByEmp[emp.id] || [], result);
                    currentCheckIn = null;
                }
            });

            if (currentCheckIn) {
                processAdminSession(emp, currentCheckIn, { timestamp: format(new Date(), 'yyyy-MM-dd HH:mm:ss') }, absByEmp[emp.id] || [], result);
            }
        });

        return result.sort((a, b) => {
            const dateA = (a.date || '').split('.').reverse().join('-');
            const dateB = (b.date || '').split('.').reverse().join('-');
            const dateCompare = dateB.localeCompare(dateA);
            if (dateCompare !== 0) return dateCompare;
            return (b.checkIn || '').localeCompare(a.checkIn || '');
        });
    }, [records, absences, employees, currentUser]);

    const processAdminSession = (emp: Employee, cin: AttendanceRecord, cout: { timestamp: string, type?: string, id?: string, image?: string }, empAbs: AbsenceRecord[], result: any[]) => {
        const start = parseAnyDate(cin.timestamp);
        const end = parseAnyDate(cout.timestamp);
        const totalMins = Math.floor((end.getTime() - start.getTime()) / 60000);

        const checkInFull = parseAnyDate(cin.timestamp);
        const checkOutFull = parseAnyDate(cout.timestamp);
        
        // Normalize work start/end based on check-in date
        const baseDate = format(checkInFull, 'yyyy-MM-dd');
        const workStartFull = parseAnyDate(`${baseDate} ${emp.workStartTime || '09:00:00'}`);
        let workEndFull = parseAnyDate(`${baseDate} ${emp.workEndTime || '18:00:00'}`);
        
        // Handle overnight shifts in work schedule
        if (workEndFull <= workStartFull) {
            workEndFull = new Date(workEndFull.getTime() + 24 * 60 * 60 * 1000);
        }

        const diffStartMins = Math.floor((checkInFull.getTime() - workStartFull.getTime()) / 60000);
        const inStatus = diffStartMins > 0 ? `${formatMins(diffStartMins)} kechikdi` : 
                        diffStartMins < 0 ? `${formatMins(Math.abs(diffStartMins))} vaqtli keldi` : "O'z vaqtida";
        
        let outStatus = "Hali ishda";
        if (cout.type === 'check-out') {
            const diffEndMins = Math.floor((checkOutFull.getTime() - workEndFull.getTime()) / 60000);
            outStatus = diffEndMins < 0 ? `${formatMins(Math.abs(diffEndMins))} vaqtli ketdi` :
                        diffEndMins > 0 ? `${formatMins(diffEndMins)} kech ketdi` : "O'z vaqtida";
        }

        const sessionAbsences = empAbs.filter(a => {
            const aEnd = a.endTime || format(new Date(), 'yyyy-MM-dd HH:mm:ss');
            return a.startTime < cout.timestamp && aEnd > cin.timestamp;
        });

        let awayMins = 0;
        sessionAbsences.forEach(a => {
            if (a.status !== 'approved') {
                const overlapStart = a.startTime > cin.timestamp ? a.startTime : cin.timestamp;
                const aEnd = a.endTime || format(new Date(), 'yyyy-MM-dd HH:mm:ss');
                const overlapEnd = aEnd < cout.timestamp ? aEnd : cout.timestamp;
                
                const sDt = parseAnyDate(overlapStart);
                const eDt = parseAnyDate(overlapEnd);
                awayMins += Math.max(0, Math.floor((eDt.getTime() - sDt.getTime()) / 60000));
            }
        });

        const rawEarned = (Math.max(0, totalMins - awayMins) / 60) * (emp.hourlyRate || 0);

        result.push({
            id: cout.id ? `session-${cin.id}-${cout.id}` : `active-${cin.id}`, 
            employee: emp,
            date: formatSafe(cin.timestamp, 'dd.MM.yyyy'),
            checkIn: formatSafe(cin.timestamp, 'HH:mm'),
            checkOut: cout.type === 'check-out' ? formatSafe(cout.timestamp, 'HH:mm') : '...',
            rawInTimestamp: cin.timestamp,
            rawOutTimestamp: cout.timestamp,
            inRecordId: cin.id,
            outRecordId: cout.id || null,
            inStatus,
            outStatus,
            workedMins: Math.max(0, totalMins - awayMins),
            earned: isNaN(rawEarned) ? 0 : rawEarned,
            awayMins: awayMins,
            absences: sessionAbsences,
            image: cin.image || null
        });
    };

    const filteredSessions = React.useMemo(() => {
        return sessions.filter(s => {
            const matchesBranch = !filterBranchId || s.employee?.branchId === filterBranchId;
            const matchesDate = !filterDate || s.date === formatSafe(filterDate, 'dd.MM.yyyy');
            const matchesName = !filterName || (s.employee?.fullName || '').toLowerCase().includes(filterName.toLowerCase());
            return matchesBranch && matchesDate && matchesName;
        });
    }, [sessions, filterBranchId, filterDate, filterName]);

    const FilterBar = React.useMemo(() => {
        return (
            <View style={styles.filterBar}>
                <View style={styles.filterCol}>
                    <Text style={styles.filterLabel}>Filial</Text>
                    <ScrollView horizontal showsHorizontalScrollIndicator={false} style={styles.filterScroll}>
                        <TouchableOpacity 
                            style={[styles.filterChip, !filterBranchId && styles.filterChipActive]} 
                            onPress={() => setFilterBranchId('')}
                        >
                            <Text style={[styles.filterChipText, !filterBranchId && styles.filterChipTextActive]}>Barchasi</Text>
                        </TouchableOpacity>
                        {branches.map(b => (
                            <TouchableOpacity 
                                key={b.id} 
                                style={[styles.filterChip, filterBranchId === b.id && styles.filterChipActive]} 
                                onPress={() => setFilterBranchId(b.id)}
                            >
                                <Text style={[styles.filterChipText, filterBranchId === b.id && styles.filterChipTextActive]}>{b.name}</Text>
                            </TouchableOpacity>
                        ))}
                    </ScrollView>
                </View>
                {['reporting', 'absences', 'employees'].includes(activeTab) && (
                    <View style={styles.filterCol}>
                        <Text style={styles.filterLabel}>Sana / Ism</Text>
                        <View style={{ flexDirection: 'row', gap: 8 }}>
                            {(activeTab === 'reporting' || activeTab === 'absences') && (
                                <TextInput 
                                    style={[styles.dateInput, { flex: 1.2 }]}
                                    placeholder="YYYY-MM-DD"
                                    placeholderTextColor="#9CA3AF"
                                    value={filterDate}
                                    onChangeText={setFilterDate}
                                />
                            )}
                            <TextInput 
                                style={[styles.dateInput, { flex: 2 }]}
                                placeholder="Ism bo'yicha..."
                                placeholderTextColor="#9CA3AF"
                                value={filterName}
                                onChangeText={setFilterName}
                            />
                            {(filterDate !== '' || filterName !== '') && (
                                <TouchableOpacity 
                                    onPress={() => { setFilterDate(''); setFilterName(''); }} 
                                    style={styles.clearDate}
                                >
                                    <X size={14} color="#EF4444" />
                                </TouchableOpacity>
                            )}
                        </View>
                    </View>
                )}
            </View>
        );
    }, [branches, filterBranchId, filterName, filterDate, activeTab]);

    const renderSessionItem = React.useCallback(({ item }: { item: any }) => (
        <View style={styles.sessionCard}>
            <View style={styles.sessionHead}>
                <Text style={styles.sessionEmpName}>{item.employee?.fullName || 'Xodim'}</Text>
                <Text style={styles.sessionDate}>{item.date || '...'}</Text>
            </View>
            <View style={styles.sessionRow}>
                <View style={styles.sessionInfo}>
                    <Text style={styles.infoLabel}>Kirish</Text>
                    <View style={{ flexDirection: 'row', alignItems: 'center', gap: 6 }}>
                        <Text style={styles.infoVal}>{item.checkIn}</Text>
                        {currentUser?.role === 'superadmin' && (
                            <TouchableOpacity onPress={() => {
                                setEditingAtt({ id: item.id, time: item.rawInTimestamp, type: 'Kirish', recordId: item.inRecordId });
                                setNewAttTime(item.checkIn);
                                setShowEditAtt(true);
                            }}>
                                <Edit size={14} color="#3B82F6" />
                            </TouchableOpacity>
                        )}
                    </View>
                    <Text style={[styles.statusBadge, { color: (item.inStatus || '').includes('kechikdi') ? '#EF4444' : '#10B981' }]}>{item.inStatus || ''}</Text>
                </View>
                <View style={styles.sessionInfo}>
                    <Text style={styles.infoLabel}>Chiqish</Text>
                    <View style={{ flexDirection: 'row', alignItems: 'center', gap: 6 }}>
                        <Text style={[styles.infoVal, { color: '#10B981' }]}>{item.checkOut}</Text>
                        {currentUser?.role === 'superadmin' && !!item.outRecordId && (
                            <TouchableOpacity onPress={() => {
                                setEditingAtt({ id: item.id, time: item.rawOutTimestamp, type: 'Chiqish', recordId: item.outRecordId });
                                setNewAttTime(item.checkOut);
                                setShowEditAtt(true);
                            }}>
                                <Edit size={14} color="#3B82F6" />
                            </TouchableOpacity>
                        )}
                    </View>
                    <Text style={[styles.statusBadge, { color: (item.outStatus || '').includes('vaqtli ketdi') ? '#EF4444' : '#10B981' }]}>{item.outStatus || ''}</Text>
                </View>
                <View style={styles.sessionInfo}>
                    <Text style={styles.infoLabel}>Sof vaqt</Text>
                    <Text style={[styles.infoVal, { color: '#10B981' }]}>
                        {Math.floor((item.workedMins || 0) / 60)}:{(item.workedMins || 0) % 60 < 10 ? '0' : ''}{(item.workedMins || 0) % 60}
                    </Text>
                </View>
            </View>
        </View>
    ), [currentUser]);

    const renderAbsences = () => {
        const filteredAbsences = absences.filter(a => {
            const matchesBranch = !filterBranchId || a.branchId === filterBranchId;
            const matchesDate = !filterDate || formatSafe(a.startTime, 'yyyy-MM-dd') === filterDate;
            const roleOk = (currentUser?.role === 'superadmin' || currentUser?.role === 'admin') ||
                         (currentUser?.role === 'manager' && a.branchId === currentUser.branchId) ||
                         (a.employeeId === currentUser?.id);
            return matchesBranch && matchesDate && roleOk;
        });

        return (
            <FlatList
                ListHeaderComponent={<Text style={styles.sectionTitle}>Uzoqlashishlar</Text>}
                data={filteredAbsences}
                keyExtractor={item => `abs-${item.id}`}
                renderItem={({ item }) => {
                    const emp = employees.find(e => e.id === item.employeeId);
                    return (
                        <View style={styles.itemCard}>
                            <Clock size={24} color="#6B7280" />
                            <View style={styles.itemMain}>
                                <Text style={styles.itemName}>{emp?.fullName || 'Noma\'lum'}</Text>
                                <Text style={styles.itemDetail}>{formatSafe(item.startTime, 'HH:mm (dd.MM)')}</Text>
                            </View>
                            <View>
                                <Text style={[styles.statusBadge, item.status === 'approved' && styles.statusOk, item.status === 'rejected' && styles.statusNo]}>
                                    {item.status === 'pending' ? 'Kutilmoqda' : item.status === 'approved' ? 'Tasdiq' : 'Rad'}
                                </Text>
                                {item.status === 'pending' && (
                                    <View style={styles.actionRow}>
                                        <TouchableOpacity onPress={() => updateAbsenceStatus(item.id, 'approved')} style={styles.absBtnOk}>
                                            <Check size={16} color="#fff" />
                                        </TouchableOpacity>
                                        <TouchableOpacity onPress={() => updateAbsenceStatus(item.id, 'rejected')} style={styles.absBtnNo}>
                                            <Ban size={16} color="#fff" />
                                        </TouchableOpacity>
                                    </View>
                                )}
                            </View>
                        </View>
                    );
                }}
            />
        );
    };

    const renderReporting = () => (
        <FlatList
            ListHeaderComponent={<Text style={styles.sectionTitle}>Ish kuni hisoboti</Text>}
            data={filteredSessions}
            keyExtractor={item => String(item.id)}
            renderItem={renderSessionItem}
        />
    );

    const renderEmployees = () => {
        const filteredEmployees = employees.filter(e => {
            const matchesBranch = !filterBranchId || e.branchId === filterBranchId;
            const matchesName = !filterName || (e.fullName || '').toLowerCase().includes(filterName.toLowerCase());
            const roleOk = (currentUser?.role === 'superadmin' || currentUser?.role === 'admin') ||
                         (currentUser?.role === 'manager' && e.branchId === currentUser.branchId) ||
                         (e.id === currentUser?.id);
            return matchesBranch && roleOk && matchesName;
        });

        return (
            <FlatList
                ListHeaderComponent={(
                    <View style={styles.sectionHeader}>
                        <Text style={styles.sectionTitle}>Xodimlar</Text>
                        {(!showForm && (currentUser?.role === 'admin' || currentUser?.role === 'superadmin')) && (
                            <TouchableOpacity style={styles.addButton} onPress={() => setShowForm(true)}>
                                <Plus color="#fff" size={20} />
                                <Text style={styles.addButtonText}>Qo'shish</Text>
                            </TouchableOpacity>
                        )}
                    </View>
                )}
                data={filteredEmployees}
                keyExtractor={item => `emp-${item.id}`}
                renderItem={({ item }) => {
                    const empSessions = sessions.filter(s => s.employee?.id === item.id).slice(0, 3);
                    return (
                        <TouchableOpacity style={styles.empCard} onPress={() => { setSelectedEmployee(item); setShowEmployeeDetail(true); }}>
                            <View style={styles.empCardMain}>
                                <Users size={28} color="#3B82F6" />
                                <View style={styles.itemMain}>
                                    <Text style={styles.itemName}>{item.fullName}</Text>
                                    <Text style={styles.itemDetail}>{item.position}</Text>
                                </View>
                                {(currentUser?.role === 'admin' || currentUser?.role === 'superadmin') && (
                                    <View style={styles.actionRow}>
                                        <TouchableOpacity onPress={() => handleResetPassword(item.id)}><Key size={18} color="#F59E0B" /></TouchableOpacity>
                                        <TouchableOpacity onPress={() => handleEditEmployee(item)}><Edit size={20} color="#3B82F6" /></TouchableOpacity>
                                        <TouchableOpacity onPress={() => handleDeleteEmployee(item.id)}><Trash2 size={20} color="#EF4444" /></TouchableOpacity>
                                    </View>
                                )}
                            </View>
                            {empSessions.length > 0 && (
                                <View style={styles.empSnippet}>
                                    <Text style={styles.snippetTitle}>Oxirgi 3 kunlik faoliyat:</Text>
                                    <View style={styles.snippetGrid}>
                                        {empSessions.map((s, idx) => (
                                            <View key={idx} style={styles.snippetItem}>
                                                <Text style={styles.snippetDate}>{(s.date || '').split('.')[0]}.{(s.date || '').split('.')[1]}</Text>
                                                <Text style={styles.snippetTime}>{s.checkIn || ''}-{s.checkOut || ''}</Text>
                                            </View>
                                        ))}
                                    </View>
                                </View>
                            )}
                        </TouchableOpacity>
                    );
                }}
            />
        );
    };

    const renderBranches = () => (
        <FlatList
            ListHeaderComponent={(
                <View style={styles.sectionHeader}>
                    <Text style={styles.sectionTitle}>Filiallar</Text>
                    {!showForm && (
                        <TouchableOpacity style={styles.addButton} onPress={() => setShowForm(true)}>
                            <Plus color="#fff" size={20} />
                            <Text style={styles.addButtonText}>Qo'shish</Text>
                        </TouchableOpacity>
                    )}
                </View>
            )}
            data={branches}
            renderItem={({ item }) => (
                <View style={styles.itemCard}>
                    <MapPin size={24} color="#EF4444" />
                    <View style={styles.itemMain}>
                        <Text style={styles.itemName}>{item.name}</Text>
                        <Text style={styles.itemDetail}>{item.latitude}, {item.longitude}</Text>
                    </View>
                </View>
            )}
        />
    );

    const handleSavePayment = async () => {
        if (!payEmpId || !payAmount) return Alert.alert('Xato', 'To\'ldiring');
        await StorageService.savePayment({
            employeeId: payEmpId,
            amount: parseFloat(payAmount),
            type: payType,
            comment: payComment,
            createdBy: currentUser?.id
        });
        resetForm();
        loadData();
    };

    const renderPayments = () => (
        <FlatList
            ListHeaderComponent={<Text style={styles.sectionTitle}>To'lovlar</Text>}
            data={payments}
            renderItem={({ item }) => {
                const emp = employees.find(e => e.id === item.employeeId);
                return (
                    <View style={styles.itemCard}>
                        <Banknote size={24} color="#10B981" />
                        <View style={styles.itemMain}>
                            <Text style={styles.itemName}>{emp?.fullName || 'Noma\'lum'}</Text>
                            <Text style={styles.itemDetail}>{(item.amount || 0).toLocaleString()} UZS</Text>
                        </View>
                    </View>
                );
            }}
        />
    );

    const updateOffDayStatus = async (id: string, status: 'approved' | 'rejected') => {
        const success = await StorageService.updateOffDayRequestStatus(id, status);
        if (success) {
            Alert.alert('Muvaffaqiyatli', status === 'approved' ? 'Tasdiqlandi' : 'Rad etildi');
            loadData();
        }
    };

    const renderOffDays = () => (
        <FlatList
            ListHeaderComponent={(
                <View>
                    <Text style={styles.sectionTitle}>Dam olish so'rovlari</Text>
                    {FilterBar}
                </View>
            )}
            data={offDayRequests.filter(o => !filterBranchId || employees.find(e => e.id === o.employeeId)?.branchId === filterBranchId)}
            keyExtractor={item => `off-${item.id}`}
            renderItem={({ item }) => {
                const emp = employees.find(e => e.id === item.employeeId);
                return (
                    <View style={styles.itemCard}>
                        <Calendar size={24} color="#8B5CF6" />
                        <View style={styles.itemMain}>
                            <Text style={styles.itemName}>{emp?.fullName || 'Noma\'lum'}</Text>
                            <Text style={styles.itemDetail}>{item.requestDate} • {item.reason}</Text>
                        </View>
                        <View>
                            <Text style={[styles.statusBadge, item.status === 'approved' && styles.statusOk, item.status === 'rejected' && styles.statusNo]}>
                                {item.status === 'pending' ? 'Kutilmoqda' : item.status === 'approved' ? 'Tasdiq' : 'Rad'}
                            </Text>
                            {item.status === 'pending' && (
                                <View style={styles.actionRow}>
                                    <TouchableOpacity onPress={() => updateOffDayStatus(item.id, 'approved')} style={styles.absBtnOk}><Check size={16} color="#fff" /></TouchableOpacity>
                                    <TouchableOpacity onPress={() => updateOffDayStatus(item.id, 'rejected')} style={styles.absBtnNo}><Ban size={16} color="#fff" /></TouchableOpacity>
                                </View>
                            )}
                        </View>
                    </View>
                );
            }}
        />
    );

    const updateFineAdminStatus = async (id: string, status: 'approved' | 'rejected') => {
        const success = await StorageService.updateFineStatus(id, status);
        if (success) {
            Alert.alert('Muvaffaqiyatli', status === 'approved' ? 'Jarima qo\'llanildi' : 'Jarima bekor qilindi');
            loadData();
        }
    };

    const renderFines = () => (
        <FlatList
            ListHeaderComponent={(
                <View>
                    <Text style={styles.sectionTitle}>Jarimalarni Boshqarish</Text>
                    {FilterBar}
                </View>
            )}
            data={fines.filter(f => !filterBranchId || employees.find(e => e.id === f.employeeId)?.branchId === filterBranchId)}
            keyExtractor={item => `fine-${item.id}`}
            renderItem={({ item }) => {
                const emp = employees.find(e => e.id === item.employeeId);
                return (
                    <View style={styles.itemCard}>
                        <Banknote size={24} color="#EF4444" />
                        <View style={styles.itemMain}>
                            <Text style={styles.itemName}>{emp?.fullName || 'Noma\'lum'} <Text style={{ fontSize: 13, color: '#6B7280', fontWeight: 'normal' }}>({branches.find(b => b.id === emp?.branchId)?.name || ''})</Text></Text>
                            <Text style={styles.itemDetail}>{formatSafe(item.date, 'dd.MM.yyyy HH:mm')} • {item.reason}</Text>
                            <Text style={{ color: '#EF4444', fontWeight: 'bold', marginTop: 4 }}>-{item.amount.toLocaleString()} UZS</Text>
                        </View>
                        <View>
                            <Text style={[styles.statusBadge, item.status === 'rejected' && styles.statusOk, item.status === 'approved' && styles.statusNo]}>
                                {item.status === 'pending' ? 'Kutilmoqda' : item.status === 'approved' ? 'Qo\'llandi' : 'Bekor qilingan'}
                            </Text>
                            {item.status === 'pending' && (
                                <View style={styles.actionRow}>
                                    <TouchableOpacity onPress={() => updateFineAdminStatus(item.id, 'rejected')} style={styles.absBtnOk}>
                                        <Check size={16} color="#fff" />
                                    </TouchableOpacity>
                                    <TouchableOpacity onPress={() => updateFineAdminStatus(item.id, 'approved')} style={styles.absBtnNo}>
                                        <X size={16} color="#fff" />
                                    </TouchableOpacity>
                                </View>
                            )}
                        </View>
                    </View>
                );
            }}
        />
    );

    const handleSaveRole = async () => {
        if (!roleName || rolePerms.length === 0) return Alert.alert('Xato', 'Nomi va kamida bitta imkoniyatni tanlang');
        const rData = { name: roleName, permissions: rolePerms };
        if (editingId) {
            await StorageService.updateRole({ id: editingId, ...rData });
        } else {
            await StorageService.saveRole(rData);
        }
        resetForm();
        loadData();
    };

    const handleEditRole = (role: any) => {
        setEditingId(role.id);
        setRoleName(role.name);
        setRolePerms(role.permissions || []);
        setShowForm(true);
    };

    const togglePermission = (id: string) => {
        if (rolePerms.includes(id)) {
            setRolePerms(rolePerms.filter(p => p !== id));
        } else {
            setRolePerms([...rolePerms, id]);
        }
    };

    const renderRoles = () => (
        <FlatList
            ListHeaderComponent={(
                <View style={styles.sectionHeader}>
                    <Text style={styles.sectionTitle}>Rollar</Text>
                    {!showForm && (
                        <TouchableOpacity style={styles.addButton} onPress={() => setShowForm(true)}>
                            <Plus color="#fff" size={20} />
                            <Text style={styles.addButtonText}>Qo'shish</Text>
                        </TouchableOpacity>
                    )}
                </View>
            )}
            data={rolesList}
            keyExtractor={item => `role-${item.id}`}
            renderItem={({ item }) => (
                <View style={styles.itemCard}>
                    <Shield size={24} color="#6366F1" />
                    <View style={styles.itemMain}>
                        <Text style={styles.itemName}>{item.name}</Text>
                        <Text style={styles.itemDetail}>{item.permissions?.length || 0} ta imkoniyat</Text>
                    </View>
                    <TouchableOpacity onPress={() => handleEditRole(item)}><Edit size={20} color="#3B82F6" /></TouchableOpacity>
                    <TouchableOpacity onPress={async () => { await StorageService.deleteRole(item.id); loadData(); }} style={{ marginLeft: 12 }}>
                        <Trash2 size={20} color="#EF4444" />
                    </TouchableOpacity>
                </View>
            )}
        />
    );

    const renderFineTypes = () => (
        <FlatList
            ListHeaderComponent={(
                <View style={styles.sectionHeader}>
                    <Text style={styles.sectionTitle}>Jarima turlari</Text>
                    {!showForm && (currentUser?.role === 'superadmin' || currentUser?.role === 'admin') && (
                        <TouchableOpacity style={styles.addButton} onPress={() => setShowForm(true)}>
                            <Plus color="#fff" size={20} />
                            <Text style={styles.addButtonText}>Qo'shish</Text>
                        </TouchableOpacity>
                    )}
                </View>
            )}
            data={fineTypes}
            keyExtractor={item => `ft-${item.id}`}
            renderItem={({ item }) => (
                <View style={styles.itemCard}>
                    <Banknote size={24} color="#EF4444" />
                    <View style={styles.itemMain}>
                        <Text style={styles.itemName}>{item.name}</Text>
                        <Text style={[styles.itemDetail, { fontWeight: 'bold', color: '#EF4444' }]}>{item.amount.toLocaleString()} UZS</Text>
                        {item.description ? <Text style={styles.itemDetail}>{item.description}</Text> : null}
                    </View>
                    {(currentUser?.role === 'superadmin' || currentUser?.role === 'admin') && (
                        <View style={styles.actionRow}>
                            <TouchableOpacity onPress={() => handleEditFineType(item)}><Edit size={20} color="#3B82F6" /></TouchableOpacity>
                            <TouchableOpacity onPress={() => handleDeleteFineType(item.id)}><Trash2 size={20} color="#EF4444" /></TouchableOpacity>
                        </View>
                    )}
                </View>
            )}
        />
    );

    const renderLogs = () => (
        <FlatList
            ListHeaderComponent={<Text style={styles.sectionTitle}>O'zgartirishlar tarixi</Text>}
            data={editLogs}
            keyExtractor={item => `log-${item.id}`}
            renderItem={({ item }) => (
                <View style={styles.itemCard}>
                    <History size={24} color="#6B7280" />
                    <View style={styles.itemMain}>
                        <Text style={styles.itemName}>
                            <Text style={{color: '#3B82F6'}}>{item.admin_name || 'Admin'}</Text> tomonidan o'zgartirildi
                        </Text>
                        <Text style={styles.itemDetail}>
                            <Text style={{fontWeight: '700', color: '#111827'}}>Xodim: </Text>
                            {item.employee_name || '...'}
                        </Text>
                        <Text style={styles.itemDetail}>
                            <Text style={{fontWeight: '700', color: '#111827'}}>Asl sana: </Text>
                            {(item.old_timestamp || '').substring(0, 10)}
                        </Text>
                        <View style={{flexDirection: 'row', alignItems: 'center', gap: 6, marginVertical: 4}}>
                            <Text style={{fontSize: 14, fontWeight: '700', color: '#EF4444'}}>{(item.old_timestamp || '').substring(11, 16)}</Text>
                            <ArrowRight size={14} color="#9CA3AF" />
                            <Text style={{fontSize: 14, fontWeight: '700', color: '#10B981'}}>{(item.new_timestamp || '').substring(11, 16)}</Text>
                        </View>
                        {item.reason ? <Text style={[styles.itemDetail, { fontStyle: 'italic' }]}>Sabab: {item.reason}</Text> : null}
                        <Text style={[styles.itemDetail, { fontSize: 10, color: '#9CA3AF', marginTop: 4 }]}>
                            Tahrir vaqti: {formatSafe(item.created_at, 'dd.MM.yyyy HH:mm')}
                        </Text>
                    </View>
                </View>
            )}
        />
    );

    const renderWarnings = () => (
        <FlatList
            ListHeaderComponent={<Text style={styles.sectionTitle}>Kechikish ogohlantirishlari</Text>}
            data={warnings.filter(w => !filterBranchId || employees.find(e => e.id === w.employeeId)?.branchId === filterBranchId)}
            keyExtractor={item => `warn-${item.id}`}
            renderItem={({ item }) => {
                const emp = employees.find(e => e.id === item.employeeId);
                return (
                    <View style={styles.itemCard}>
                        <AlertTriangle size={24} color="#F59E0B" />
                        <View style={styles.itemMain}>
                            <Text style={styles.itemName}>{emp?.fullName || 'Noma\'lum'}</Text>
                            <Text style={styles.itemDetail}>{item.reason}</Text>
                            <Text style={[styles.itemDetail, { fontSize: 12, color: '#9CA3AF' }]}>{formatSafe(item.timestamp, 'HH:mm (dd.MM.yyyy)')}</Text>
                        </View>
                    </View>
                );
            }}
        />
    );

    return (
        <View style={[styles.container, { paddingTop: insets.top || 20 }]}>
            <View style={styles.headerRow}>
                <TouchableOpacity onPress={() => setIsMenuOpen(true)} style={styles.menuBtn}><Menu size={28} color="#111827" /></TouchableOpacity>
                <View>
                    <Text style={styles.title}>Admin Panel</Text>
                    <Text style={styles.subtitle}>Boshqaruv</Text>
                </View>
            </View>

            {loading ? (
                <View style={{ flex: 1, justifyContent: 'center', alignItems: 'center' }}>
                    <ActivityIndicator size="large" color="#3B82F6" />
                </View>
            ) : !currentUser ? (
                <View style={{ flex: 1, justifyContent: 'center', alignItems: 'center', padding: 20 }}>
                    <Text style={{ fontSize: 16, color: '#6B7280', textAlign: 'center' }}>Xodim ma'lumotlari topilmadi.</Text>
                    <TouchableOpacity style={{ marginTop: 20, padding: 12, backgroundColor: '#EF4444', borderRadius: 8 }} onPress={handleLogout}>
                        <Text style={{ color: '#fff', fontWeight: 'bold' }}>Chiqish</Text>
                    </TouchableOpacity>
                </View>
            ) : (
                <>

            {isMenuOpen && (
                <View style={styles.menuOverlay}>
                    <TouchableOpacity style={styles.menuCloseArea} onPress={() => setIsMenuOpen(false)} activeOpacity={1} />
                    <View style={[styles.sideMenu, { paddingTop: (insets.top || 0) + 20 }]}>
                        <TouchableOpacity style={[styles.menuItem, activeTab === 'reporting' && styles.activeMenuItem]} onPress={() => { setActiveTab('reporting'); setIsMenuOpen(false) }}>
                            <ClipboardList size={20} color={activeTab === 'reporting' ? '#3B82F6' : '#6B7280'} />
                            <Text style={[styles.menuItemText, activeTab === 'reporting' && styles.activeMenuItemText]}>Hisobotlar</Text>
                        </TouchableOpacity>
                        
                        {hasPermission('absences') && (
                            <TouchableOpacity style={[styles.menuItem, activeTab === 'absences' && styles.activeMenuItem]} onPress={() => { setActiveTab('absences'); setIsMenuOpen(false) }}>
                                <Clock size={20} color={activeTab === 'absences' ? '#3B82F6' : '#6B7280'} />
                                <Text style={[styles.menuItemText, activeTab === 'absences' && styles.activeMenuItemText]}>Uzoqlashlar</Text>
                            </TouchableOpacity>
                        )}
                        
                        {hasPermission('employees') && (
                            <TouchableOpacity style={[styles.menuItem, activeTab === 'employees' && styles.activeMenuItem]} onPress={() => { setActiveTab('employees'); setIsMenuOpen(false) }}>
                                <Users size={20} color={activeTab === 'employees' ? '#3B82F6' : '#6B7280'} />
                                <Text style={[styles.menuItemText, activeTab === 'employees' && styles.activeMenuItemText]}>Xodimlar</Text>
                            </TouchableOpacity>
                        )}
                        
                        {hasPermission('branches') && (
                            <TouchableOpacity style={[styles.menuItem, activeTab === 'branches' && styles.activeMenuItem]} onPress={() => { setActiveTab('branches'); setIsMenuOpen(false) }}>
                                <MapPin size={20} color={activeTab === 'branches' ? '#3B82F6' : '#6B7280'} />
                                <Text style={[styles.menuItemText, activeTab === 'branches' && styles.activeMenuItemText]}>Filiallar</Text>
                            </TouchableOpacity>
                        )}
                        
                        {hasPermission('offdays') && (
                            <TouchableOpacity style={[styles.menuItem, activeTab === 'offdays' && styles.activeMenuItem]} onPress={() => { setActiveTab('offdays'); setIsMenuOpen(false) }}>
                                <Calendar size={20} color={activeTab === 'offdays' ? '#3B82F6' : '#6B7280'} />
                                <Text style={[styles.menuItemText, activeTab === 'offdays' && styles.activeMenuItemText]}>Dam olish</Text>
                            </TouchableOpacity>
                        )}

                        {hasPermission('payments') && (
                            <TouchableOpacity style={[styles.menuItem, activeTab === 'payments' && styles.activeMenuItem]} onPress={() => { setActiveTab('payments'); setIsMenuOpen(false) }}>
                                <CreditCard size={20} color={activeTab === 'payments' ? '#3B82F6' : '#6B7280'} />
                                <Text style={[styles.menuItemText, activeTab === 'payments' && styles.activeMenuItemText]}>To'lovlar</Text>
                            </TouchableOpacity>
                        )}

                        {currentUser?.role === 'superadmin' && (
                             <TouchableOpacity style={[styles.menuItem, activeTab === 'roles' && styles.activeMenuItem]} onPress={() => { setActiveTab('roles'); setIsMenuOpen(false) }}>
                                <Shield size={20} color={activeTab === 'roles' ? '#3B82F6' : '#6B7280'} />
                                <Text style={[styles.menuItemText, activeTab === 'roles' && styles.activeMenuItemText]}>Rollar</Text>
                            </TouchableOpacity>
                        )}

                        {currentUser?.role === 'superadmin' && (
                             <TouchableOpacity style={[styles.menuItem, activeTab === 'logs' && styles.activeMenuItem]} onPress={() => { setActiveTab('logs'); setIsMenuOpen(false) }}>
                                <History size={20} color={activeTab === 'logs' ? '#3B82F6' : '#6B7280'} />
                                <Text style={[styles.menuItemText, activeTab === 'logs' && styles.activeMenuItemText]}>O'zgartirishlar</Text>
                            </TouchableOpacity>
                        )}

                        {hasPermission('fines') && (
                             <TouchableOpacity style={[styles.menuItem, activeTab === 'fines' && styles.activeMenuItem]} onPress={() => { setActiveTab('fines'); setIsMenuOpen(false) }}>
                                <Banknote size={20} color={activeTab === 'fines' ? '#3B82F6' : '#6B7280'} />
                                <Text style={[styles.menuItemText, activeTab === 'fines' && styles.activeMenuItemText]}>Jarimalar</Text>
                            </TouchableOpacity>
                        )}
                        
                        {hasPermission('fine_types') && (
                             <TouchableOpacity style={[styles.menuItem, activeTab === 'fine_types' && styles.activeMenuItem]} onPress={() => { setActiveTab('fine_types'); setIsMenuOpen(false) }}>
                                <Settings size={20} color={activeTab === 'fine_types' ? '#3B82F6' : '#6B7280'} />
                                <Text style={[styles.menuItemText, activeTab === 'fine_types' && styles.activeMenuItemText]}>Jarima turlari</Text>
                            </TouchableOpacity>
                        )}
                        
                        {hasPermission('warnings') && (
                             <TouchableOpacity style={[styles.menuItem, activeTab === 'warnings' && styles.activeMenuItem]} onPress={() => { setActiveTab('warnings'); setIsMenuOpen(false) }}>
                                <AlertTriangle size={20} color={activeTab === 'warnings' ? '#3B82F6' : '#6B7280'} />
                                <Text style={[styles.menuItemText, activeTab === 'warnings' && styles.activeMenuItemText]}>Kechikishlar</Text>
                            </TouchableOpacity>
                        )}

                        <TouchableOpacity style={styles.logoutBtn} onPress={handleLogout}><LogOut size={20} color="#EF4444" /><Text style={styles.logoutBtnText}>Chiqish</Text></TouchableOpacity>
                    </View>
                </View>
            )}

            <View style={{ flex: 1 }}>
                {['reporting', 'absences', 'employees'].includes(activeTab) && FilterBar}
                {activeTab === 'reporting' && renderReporting()}
                {activeTab === 'absences' && renderAbsences()}
                {activeTab === 'employees' && renderEmployees()}
                {activeTab === 'branches' && renderBranches()}
                {activeTab === 'payments' && renderPayments()}
                {activeTab === 'offdays' && renderOffDays()}
                {activeTab === 'fines' && renderFines()}
                {activeTab === 'fine_types' && renderFineTypes()}
                {activeTab === 'roles' && renderRoles()}
                {activeTab === 'logs' && renderLogs()}
                {activeTab === 'warnings' && renderWarnings()}
            </View>

            {/* Attendance Edit Modal (Superadmin only) */}
            <Modal visible={showEditAtt} animationType="fade" transparent onRequestClose={() => setShowEditAtt(false)}>
                <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : 'height'} style={{ flex: 1 }}>
                    <TouchableWithoutFeedback onPress={() => { Keyboard.dismiss(); setShowEditAtt(false); }}>
                        <View style={styles.modalOverlay}>
                            <TouchableWithoutFeedback onPress={Keyboard.dismiss}>
                                <View style={styles.modalContent}>
                                    <View style={styles.modalHeader}>
                                        <Text style={styles.modalTitle}>Vaqtni o'zgartirish</Text>
                                        <TouchableOpacity 
                                            style={{ padding: 10, margin: -10, zIndex: 50 }} 
                                            hitSlop={{ top: 20, bottom: 20, left: 20, right: 20 }} 
                                            onPress={() => setShowEditAtt(false)}
                                        >
                                            <X size={24} color="#6B7280" />
                                        </TouchableOpacity>
                                    </View>
                                    <View style={styles.form}>
                            <Text style={styles.inputLabel}>Turi: {editingAtt?.type}</Text>
                            <Text style={styles.inputLabel}>Eski vaqt: {editingAtt?.time?.substring(11, 19) || '...'}</Text>
                            
                            <Text style={styles.inputLabel}>Yangi vaqt (HH:mm):</Text>
                            <TextInput 
                                style={styles.input} 
                                value={newAttTime} 
                                onChangeText={setNewAttTime} 
                                placeholder="Masalan: 09:15"
                                placeholderTextColor="#9CA3AF"
                                maxLength={5}
                            />

                            <Text style={styles.inputLabel}>O'zgartirish sababi:</Text>
                            <TextInput 
                                style={[styles.input, { height: 80 }]} 
                                value={editReason} 
                                onChangeText={setEditReason} 
                                placeholder="Nega o'zgartirilmoqda?"
                                placeholderTextColor="#9CA3AF"
                                multiline
                            />

                            <TouchableOpacity 
                                style={[styles.saveBtn, (!newAttTime || !editReason) && { opacity: 0.5 }]} 
                                onPress={handleSaveAttTime}
                                disabled={!newAttTime || !editReason}
                            >
                                <Text style={styles.saveBtnText}>Saqlash</Text>
                            </TouchableOpacity>
                        </View>
                    </View>
                </TouchableWithoutFeedback>
            </View>
        </TouchableWithoutFeedback>
    </KeyboardAvoidingView>
</Modal>

            {/* Forms Modal */}
            <Modal visible={showForm} animationType="slide" transparent onRequestClose={resetForm}>
                <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : 'height'} style={{ flex: 1 }}>
                    <TouchableWithoutFeedback onPress={() => { Keyboard.dismiss(); resetForm(); }}>
                        <View style={styles.modalOverlay}>
                            <TouchableWithoutFeedback onPress={Keyboard.dismiss}>
                                <View style={styles.modalContent}>
                                    <View style={styles.modalHeader}>
                                        <Text style={styles.modalTitle}>{editingId ? 'Tahrirlash' : 'Yangi qo\'shish'}</Text>
                                        <TouchableOpacity 
                                            style={{ padding: 10, margin: -10, zIndex: 50 }} 
                                            hitSlop={{ top: 20, bottom: 20, left: 20, right: 20 }} 
                                            onPress={resetForm}
                                        >
                                            <X size={24} color="#6B7280" />
                                        </TouchableOpacity>
                                    </View>
                                    <ScrollView showsVerticalScrollIndicator={false} keyboardShouldPersistTaps="handled">
                            {activeTab === 'branches' && (
                                <View style={styles.form}>
                                    <TextInput style={styles.input} placeholder="Filial nomi" placeholderTextColor="#9CA3AF" value={branchName} onChangeText={setBranchName} />
                                    <TextInput style={styles.input} placeholder="Latitude" placeholderTextColor="#9CA3AF" value={lat} onChangeText={setLat} keyboardType="numeric" />
                                    <TextInput style={styles.input} placeholder="Longitude" placeholderTextColor="#9CA3AF" value={lon} onChangeText={setLon} keyboardType="numeric" />
                                    <TextInput style={styles.input} placeholder="Radius (metr)" placeholderTextColor="#9CA3AF" value={radius} onChangeText={setRadius} keyboardType="numeric" />
                                    <TouchableOpacity style={styles.saveBtn} onPress={handleSaveBranch}><Text style={styles.saveBtnText}>Saqlash</Text></TouchableOpacity>
                                </View>
                            )}
                            {activeTab === 'employees' && (
                                <View style={styles.form}>
                                    <TextInput style={styles.input} placeholder="F.I.SH" placeholderTextColor="#9CA3AF" value={empName} onChangeText={setEmpName} />
                                    <TextInput style={styles.input} placeholder="Telefon" placeholderTextColor="#9CA3AF" value={empPhone} onChangeText={setEmpPhone} keyboardType="phone-pad" />
                                    <TextInput style={styles.input} placeholder="Email" placeholderTextColor="#9CA3AF" value={empEmail} onChangeText={setEmpEmail} keyboardType="email-address" autoCapitalize="none" />
                                    <TextInput style={styles.input} placeholder="Lavozim" placeholderTextColor="#9CA3AF" value={empPos} onChangeText={setEmpPos} />
                                    
                                    <Text style={styles.formLabel}>Filialni tanlang:</Text>
                                    <ScrollView horizontal showsHorizontalScrollIndicator={false} style={styles.chipRow} keyboardShouldPersistTaps="handled">
                                        {branches.map(b => (
                                            <TouchableOpacity key={b.id} style={[styles.chip, empBranchId === b.id && styles.chipActive]} onPress={() => setEmpBranchId(b.id)}>
                                                <Text style={[styles.chipText, empBranchId === b.id && styles.chipTextActive]}>{b.name}</Text>
                                            </TouchableOpacity>
                                        ))}
                                    </ScrollView>

                                    <Text style={styles.formLabel}>Asosiy Rol:</Text>
                                    <View style={styles.chipRow}>
                                        {roles.map(r => (
                                            <TouchableOpacity key={r} style={[styles.chip, empRole === r && styles.chipActive]} onPress={() => setEmpRole(r)}>
                                                <Text style={[styles.chipText, empRole === r && styles.chipTextActive]}>{roleLabels[r]}</Text>
                                            </TouchableOpacity>
                                        ))}
                                    </View>

                                    {rolesList.length > 0 && (
                                        <>
                                            <Text style={styles.formLabel}>Qo'shimcha (Maxsus) Rol:</Text>
                                            <ScrollView horizontal showsHorizontalScrollIndicator={false} style={styles.chipRow} keyboardShouldPersistTaps="handled">
                                                <TouchableOpacity style={[styles.chip, empRoleId === '' && styles.chipActive]} onPress={() => setEmpRoleId('')}>
                                                    <Text style={[styles.chipText, empRoleId === '' && styles.chipTextActive]}>Standart</Text>
                                                </TouchableOpacity>
                                                {rolesList.map(r => (
                                                    <TouchableOpacity key={r.id} style={[styles.chip, empRoleId === r.id && styles.chipActive]} onPress={() => setEmpRoleId(r.id)}>
                                                        <Text style={[styles.chipText, empRoleId === r.id && styles.chipTextActive]}>{r.name}</Text>
                                                    </TouchableOpacity>
                                                ))}
                                            </ScrollView>
                                        </>
                                    )}

                                    <TextInput style={styles.input} placeholder="Oylik maosh" placeholderTextColor="#9CA3AF" value={empSalary} onChangeText={setEmpSalary} keyboardType="numeric" />
                                    <TextInput style={styles.input} placeholder="Oyiga ruxsat etilgan dam olish kunlari" placeholderTextColor="#9CA3AF" value={empOffDays} onChangeText={setEmpOffDays} keyboardType="numeric" />
                                    
                                    <View style={styles.row}>
                                        <View style={{ flex: 1 }}>
                                            <Text style={styles.inputLabel}>Ish boshlanishi</Text>
                                            <TextInput style={styles.input} value={empStartTime} onChangeText={setEmpStartTime} placeholder="09:00" placeholderTextColor="#9CA3AF" />
                                        </View>
                                        <View style={{ width: 16 }} />
                                        <View style={{ flex: 1 }}>
                                            <Text style={styles.inputLabel}>Ish yakuni</Text>
                                            <TextInput style={styles.input} value={empEndTime} onChangeText={setEmpEndTime} placeholder="18:00" placeholderTextColor="#9CA3AF" />
                                        </View>
                                    </View>

                                    <TouchableOpacity style={styles.saveBtn} onPress={handleSaveEmployee}><Text style={styles.saveBtnText}>Saqlash</Text></TouchableOpacity>
                                </View>
                            )}
                            {activeTab === 'roles' && (
                                <View style={styles.form}>
                                    <TextInput style={styles.input} placeholder="Rol nomi (masalan: Sotuvchi)" placeholderTextColor="#9CA3AF" value={roleName} onChangeText={setRoleName} />
                                    <Text style={styles.formLabel}>Imkoniyatlarni tanlang:</Text>
                                    {PERMISSIONS.map(p => (
                                        <TouchableOpacity key={p.id} style={styles.permRow} onPress={() => togglePermission(p.id)}>
                                            <View style={[styles.checkbox, rolePerms.includes(p.id) && styles.checkboxActive]}>
                                                {rolePerms.includes(p.id) && <Check size={14} color="#fff" />}
                                            </View>
                                            <Text style={styles.permText}>{p.label}</Text>
                                        </TouchableOpacity>
                                    ))}
                                    <TouchableOpacity style={styles.saveBtn} onPress={handleSaveRole}><Text style={styles.saveBtnText}>Saqlash</Text></TouchableOpacity>
                                </View>
                            )}
                            {activeTab === 'fine_types' && (
                                <View style={styles.form}>
                                    <TextInput style={styles.input} placeholder="Jarima nomi" placeholderTextColor="#9CA3AF" value={ftName} onChangeText={setFtName} />
                                    <TextInput style={styles.input} placeholder="Summa (UZS)" placeholderTextColor="#9CA3AF" value={ftAmount} onChangeText={setFtAmount} keyboardType="numeric" />
                                    <TextInput style={[styles.input, { height: 80 }]} placeholder="Tavsif (ixtiyoriy)" placeholderTextColor="#9CA3AF" value={ftDesc} onChangeText={setFtDesc} multiline />
                                    <TouchableOpacity style={styles.saveBtn} onPress={handleSaveFineType}><Text style={styles.saveBtnText}>Saqlash</Text></TouchableOpacity>
                                </View>
                            )}
                                    </ScrollView>
                                </View>
                            </TouchableWithoutFeedback>
                        </View>
                    </TouchableWithoutFeedback>
                </KeyboardAvoidingView>
            </Modal>

            {/* Employee Detail Modal */}
            <Modal visible={showEmployeeDetail} animationType="slide" transparent onRequestClose={() => setShowEmployeeDetail(false)}>
                <TouchableWithoutFeedback onPress={() => { Keyboard.dismiss(); setShowEmployeeDetail(false); }}>
                    <View style={styles.modalOverlay}>
                        <TouchableWithoutFeedback onPress={Keyboard.dismiss}>
                            <View style={styles.modalContent}>
                                <View style={styles.modalHeader}>
                                    <Text style={styles.modalTitle}>Xodim ma'lumotlari</Text>
                                    <TouchableOpacity 
                                        style={{ padding: 10, margin: -10, zIndex: 50 }} 
                                        hitSlop={{ top: 20, bottom: 20, left: 20, right: 20 }} 
                                        onPress={() => setShowEmployeeDetail(false)}
                                    >
                                        <X size={24} color="#6B7280" />
                                    </TouchableOpacity>
                                </View>
                                
                                {selectedEmployee && (
                            <ScrollView showsVerticalScrollIndicator={false} keyboardShouldPersistTaps="handled">
                                <View style={styles.detailCard}>
                                    <View style={styles.detailHeader}>
                                         <View style={styles.detailAvatar}>
                                             <Users size={40} color="#3B82F6" />
                                         </View>
                                         <View>
                                             <Text style={styles.detailName}>{selectedEmployee.fullName}</Text>
                                             <Text style={styles.detailPos}>{selectedEmployee.position}</Text>
                                         </View>
                                    </View>

                                    <View style={styles.detailGrid}>
                                        <View style={styles.detailItem}>
                                            <Text style={styles.detailLabel}>Telefon</Text>
                                            <Text style={styles.detailVal}>{selectedEmployee.phone}</Text>
                                        </View>
                                        <View style={styles.detailItem}>
                                            <Text style={styles.detailLabel}>Email</Text>
                                            <Text style={styles.detailVal}>{selectedEmployee.email}</Text>
                                        </View>
                                        <View style={styles.detailItem}>
                                            <Text style={styles.detailLabel}>Roli</Text>
                                            <Text style={styles.detailVal}>{(roleLabels as Record<string,string>)[selectedEmployee.role] || selectedEmployee.role || 'Xodim'}</Text>
                                        </View>
                                        <View style={styles.detailItem}>
                                            <Text style={styles.detailLabel}>Filial</Text>
                                            <Text style={styles.detailVal}>{branches.find(b => b.id === selectedEmployee.branchId)?.name || 'Noma\'lum'}</Text>
                                        </View>
                                        {!!selectedEmployee.role_id && (
                                            <View style={styles.detailItem}>
                                                <Text style={styles.detailLabel}>Maxsus Rol</Text>
                                                <Text style={[styles.detailVal, {color: '#6366F1'}]}>{rolesList.find(r => r.id === selectedEmployee.role_id)?.name || 'Noma\'lum'}</Text>
                                            </View>
                                        )}
                                        <View style={styles.detailItem}>
                                            <Text style={styles.detailLabel}>Oylik</Text>
                                            <Text style={styles.detailVal}>{Math.round(selectedEmployee.monthlySalary || 0).toLocaleString()} UZS</Text>
                                        </View>
                                        <View style={styles.detailItem}>
                                            <Text style={styles.detailLabel}>Soatbay stavka</Text>
                                            <Text style={styles.detailVal}>{Math.round(selectedEmployee.hourlyRate || 0).toLocaleString()} UZS</Text>
                                        </View>
                                        <View style={styles.detailItem}>
                                            <Text style={styles.detailLabel}>Ish vaqti</Text>
                                            <Text style={styles.detailVal}>{selectedEmployee.workStartTime?.substring(0, 5)} - {selectedEmployee.workEndTime?.substring(0, 5)}</Text>
                                        </View>
                                        <View style={styles.detailItem}>
                                            <Text style={styles.detailLabel}>Ish kunlari</Text>
                                            <Text style={styles.detailVal}>{selectedEmployee.workDays} kun / oy</Text>
                                        </View>
                                    </View>
                                </View>

                                <Text style={styles.recentActivityTitle}>So'nggi faollik</Text>
                                {sessions.filter(s => s.employee?.id === selectedEmployee?.id).slice(0, 10).map((s, idx) => (
                                    <View key={idx || s.id} style={styles.activityItem}>
                                        <Text style={styles.activityDate}>{s.date || '...'}</Text>
                                        <Text style={styles.activityTime}>{(s.checkIn || '')} - {(s.checkOut || '')}</Text>
                                        <Text style={styles.activityWorked}>{Math.floor((s.workedMins || 0) / 60)}s {(Number(s.workedMins) || 0) % 60}m</Text>
                                    </View>
                                ))}
                            </ScrollView>
                        )}
                            </View>
                        </TouchableWithoutFeedback>
                    </View>
                </TouchableWithoutFeedback>
            </Modal>
                </>
            )}
        </View>
    );
}

const styles = StyleSheet.create({
    container: { flex: 1, backgroundColor: '#F9FAFB', paddingHorizontal: 20 },
    headerRow: { flexDirection: 'row', alignItems: 'center', marginBottom: 24 },
    menuBtn: { marginRight: 16, padding: 8 },
    title: { fontSize: 24, fontWeight: 'bold', color: '#111827' },
    subtitle: { fontSize: 16, color: '#6B7280', marginTop: 4 },
    menuOverlay: { position: 'absolute', top: 0, left: 0, right: 0, bottom: 0, zIndex: 100, flexDirection: 'row' },
    menuCloseArea: { flex: 1, backgroundColor: 'rgba(0,0,0,0.5)' },
    sideMenu: { width: 280, backgroundColor: '#fff', height: '100%', padding: 20 },
    menuItem: { flexDirection: 'row', alignItems: 'center', paddingVertical: 14, paddingHorizontal: 16, borderRadius: 12, marginBottom: 8, gap: 12 },
    activeMenuItem: { backgroundColor: '#EFF6FF' },
    menuItemText: { fontSize: 16, fontWeight: '600', color: '#6B7280' },
    activeMenuItemText: { color: '#3B82F6' },
    logoutBtn: { flexDirection: 'row', alignItems: 'center', padding: 16, gap: 12, marginTop: 'auto' },
    logoutBtnText: { color: '#EF4444', fontSize: 16, fontWeight: '700' },
    sectionTitle: { fontSize: 18, fontWeight: '700', color: '#111827', marginBottom: 16 },
    itemCard: { flexDirection: 'row', alignItems: 'center', backgroundColor: '#fff', padding: 16, borderRadius: 16, marginBottom: 12 },
    itemMain: { flex: 1, marginLeft: 12 },
    itemName: { fontSize: 16, fontWeight: '600', color: '#1F2937' },
    itemDetail: { fontSize: 14, color: '#6B7280', marginTop: 2 },
    statusBadge: { fontSize: 11, paddingHorizontal: 8, paddingVertical: 4, borderRadius: 6, backgroundColor: '#FEF3C7', color: '#D97706', fontWeight: '700', overflow: 'hidden' },
    statusOk: { backgroundColor: '#D1FAE5', color: '#065F46' },
    statusNo: { backgroundColor: '#FEE2E2', color: '#991B1B' },
    actionRow: { flexDirection: 'row', gap: 12, marginTop: 8 },
    absBtnOk: { backgroundColor: '#10B981', padding: 6, borderRadius: 8 },
    absBtnNo: { backgroundColor: '#EF4444', padding: 6, borderRadius: 8 },
    addButton: { flexDirection: 'row', alignItems: 'center', backgroundColor: '#3B82F6', paddingHorizontal: 12, paddingVertical: 8, borderRadius: 8, gap: 6 },
    addButtonText: { color: '#fff', fontSize: 14, fontWeight: '600' },
    sectionHeader: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 },
    sessionCard: { backgroundColor: '#fff', padding: 16, borderRadius: 20, marginBottom: 16 },
    sessionHead: { flexDirection: 'row', justifyContent: 'space-between', marginBottom: 12 },
    sessionEmpName: { fontSize: 16, fontWeight: '700', color: '#111827' },
    sessionDate: { fontSize: 14, color: '#6B7280' },
    sessionRow: { flexDirection: 'row', justifyContent: 'space-between' },
    sessionInfo: { alignItems: 'flex-start' },
    infoLabel: { fontSize: 10, color: '#9CA3AF', fontWeight: '700' },
    infoVal: { fontSize: 14, fontWeight: '600', color: '#374151' },
    modalOverlay: { flex: 1, backgroundColor: 'rgba(0,0,0,0.5)', justifyContent: 'center', padding: 20 },
    modalContent: { backgroundColor: '#fff', borderRadius: 24, padding: 20, maxHeight: '90%' },
    modalHeader: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginBottom: 20 },
    modalTitle: { fontSize: 20, fontWeight: '700', color: '#111827' },
    form: { gap: 16 },
    input: { backgroundColor: '#F3F4F6', padding: 14, borderRadius: 12, fontSize: 16, color: '#111827' },
    inputLabel: { fontSize: 12, color: '#6B7280', marginBottom: 4, marginLeft: 4 },
    formLabel: { fontSize: 14, fontWeight: '600', color: '#374151', marginBottom: 8 },
    saveBtn: { backgroundColor: '#3B82F6', padding: 16, borderRadius: 12, alignItems: 'center', marginTop: 8 },
    saveBtnText: { color: '#fff', fontSize: 16, fontWeight: '700' },
    chipRow: { flexDirection: 'row', flexWrap: 'wrap', gap: 8, marginBottom: 8 },
    chip: { paddingHorizontal: 12, paddingVertical: 8, borderRadius: 20, backgroundColor: '#F3F4F6', borderWidth: 1, borderColor: '#E5E7EB' },
    chipActive: { backgroundColor: '#EFF6FF', borderColor: '#3B82F6' },
    chipText: { fontSize: 13, color: '#6B7280', fontWeight: '500' },
    chipTextActive: { color: '#3B82F6', fontWeight: '600' },
    row: { flexDirection: 'row' },
    permRow: { flexDirection: 'row', alignItems: 'center', gap: 12, paddingVertical: 10 },
    checkbox: { width: 22, height: 22, borderRadius: 6, borderWidth: 2, borderColor: '#D1D5DB', alignItems: 'center', justifyContent: 'center' },
    checkboxActive: { backgroundColor: '#3B82F6', borderColor: '#3B82F6' },
    permText: { fontSize: 16, color: '#374151' },
    detailCard: { backgroundColor: '#F9FAFB', borderRadius: 20, padding: 16, marginBottom: 20 },
    detailHeader: { flexDirection: 'row', alignItems: 'center', gap: 16, marginBottom: 20 },
    detailAvatar: { width: 64, height: 64, borderRadius: 32, backgroundColor: '#EFF6FF', alignItems: 'center', justifyContent: 'center' },
    detailName: { fontSize: 20, fontWeight: '700', color: '#111827' },
    detailPos: { fontSize: 14, color: '#6B7280' },
    detailGrid: { flexDirection: 'row', flexWrap: 'wrap', gap: 16 },
    detailItem: { width: '47%' },
    detailLabel: { fontSize: 12, color: '#6B7280', marginBottom: 2 },
    detailVal: { fontSize: 14, fontWeight: '600', color: '#111827' },
    recentActivityTitle: { fontSize: 16, fontWeight: '700', color: '#111827', marginBottom: 12 },
    activityItem: { flexDirection: 'row', justifyContent: 'space-between', paddingVertical: 12, borderBottomWidth: 1, borderBottomColor: '#F3F4F6' },
    activityDate: { fontSize: 14, color: '#111827', fontWeight: '500' },
    activityTime: { fontSize: 14, color: '#6B7280' },
    activityWorked: { fontSize: 14, fontWeight: '600', color: '#10B981' },
    filterBar: { marginBottom: 16, gap: 12 },
    filterCol: { gap: 6 },
    filterLabel: { fontSize: 12, fontWeight: '600', color: '#9CA3AF', textTransform: 'uppercase', letterSpacing: 0.5 },
    filterScroll: { flexDirection: 'row' },
    filterChip: { paddingHorizontal: 16, paddingVertical: 8, borderRadius: 12, backgroundColor: '#fff', marginRight: 8, borderWidth: 1, borderColor: '#E5E7EB' },
    filterChipActive: { backgroundColor: '#3B82F6', borderColor: '#3B82F6' },
    filterChipText: { fontSize: 13, color: '#6B7280', fontWeight: '600' },
    filterChipTextActive: { color: '#fff' },
    dateInput: { backgroundColor: '#fff', borderWidth: 1, borderColor: '#E5E7EB', borderRadius: 12, padding: 10, fontSize: 14, color: '#111827' },
    clearDate: { position: 'absolute', right: 10, top: 32, backgroundColor: '#FEE2E2', padding: 4, borderRadius: 8 },
    empCard: { backgroundColor: '#fff', borderRadius: 20, padding: 16, marginBottom: 12, shadowColor: '#000', shadowOpacity: 0.05, shadowRadius: 10, elevation: 2 },
    empCardMain: { flexDirection: 'row', alignItems: 'center' },
    empSnippet: { marginTop: 12, paddingTop: 12, borderTopWidth: 1, borderTopColor: '#F3F4F6' },
    snippetTitle: { fontSize: 11, color: '#9CA3AF', fontWeight: '700', marginBottom: 6 },
    snippetGrid: { flexDirection: 'row', gap: 8 },
    snippetItem: { backgroundColor: '#F9FAFB', padding: 8, borderRadius: 10, flex: 1, alignItems: 'center' },
    snippetDate: { fontSize: 11, fontWeight: '700', color: '#374151' },
    snippetTime: { fontSize: 9, color: '#6B7280' },
});
