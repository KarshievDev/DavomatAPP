import { AbsenceRecord as AbsRecord, AttendanceRecord, Employee, StorageService, Fine } from '@/scripts/storage';
import { formatSafe, parseAnyDate } from '@/scripts/dateUtils';
import { endOfMonth, format, isWithinInterval, startOfMonth } from 'date-fns';
import { Calendar, Clock, DollarSign, Info, Send } from 'lucide-react-native';
import React, { useEffect, useState } from 'react';
import { FlatList, Image, Modal, RefreshControl, StyleSheet, Text, TextInput, TouchableOpacity, View, KeyboardAvoidingView, Platform, Keyboard, TouchableWithoutFeedback } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

export default function SalaryScreen() {
  const insets = useSafeAreaInsets();
  const [records, setRecords] = useState<AttendanceRecord[]>([]);
  const [absences, setAbsences] = useState<AbsRecord[]>([]);
  const [refreshing, setRefreshing] = useState(false);
  const [loading, setLoading] = useState(true);
  const [currentEmployee, setCurrentEmployee] = useState<Employee | null>(null);
  const [offDayRequests, setOffDayRequests] = useState<any[]>([]);
  const [employees, setEmployees] = useState<Employee[]>([]);
  const [selectedEmpId, setSelectedEmpId] = useState<string | null>(null);
  const [fines, setFines] = useState<Fine[]>([]);
  const [monthlyStats, setMonthlyStats] = useState({
    hours: 0,
    salary: 0,
    days: 0,
    awayMinutes: 0,
    advances: 0,
    fines: 0,
    dbBalance: 0,
  });

  const [detailModal, setDetailModal] = useState<{ visible: boolean, type: 'hours' | 'salary' | 'days' | 'advances' | 'fines' | 'away' | null }>({ visible: false, type: null });
  const [allPayments, setAllPayments] = useState<any[]>([]);
  const [offDayModal, setOffDayModal] = useState(false);
  const [requestDate, setRequestDate] = useState('');
  const [reason, setReason] = useState('');
  const [quota, setQuota] = useState<{ remainingOffDays: number, allowedOffDays: number } | null>(null);
  const [fineTypes, setFineTypes] = useState<any[]>([]);
  const [showFineTypesModal, setShowFineTypesModal] = useState(false);

  const fetchData = async (force = false) => {
    const userId = await StorageService.getCurrentUser();
    if (userId) {
      const data = await StorageService.getAllData(force);

      if (!data.employees) return;
      const me = data.employees.find(e => e.id === userId);
      setCurrentEmployee(me || null);

      let viewableEmps: Employee[] = [];
      if (me?.role === 'admin' || me?.role === 'superadmin') {
        viewableEmps = data.employees;
      } else if (me?.role === 'manager') {
        viewableEmps = data.employees.filter(e => e.branchId === me.branchId || e.id === userId);
        if (!selectedEmpId) setSelectedEmpId(userId);
      } else {
        viewableEmps = [me!].filter(Boolean);
        setSelectedEmpId(userId);
      }
      setEmployees(viewableEmps);

      const targetId = selectedEmpId || userId;
      const myRecords = (data.records || []).filter(r => r.employeeId === targetId).reverse();
      const targetEmp = data.employees.find(e => e.id === targetId);
      const myAbsences = (data.absences || []).filter(a => a.employeeId === targetId);
      const myPayments = (data.payments || []).filter(p => p.employeeId === targetId);
      const myFines = (data.fines || []).filter(f => f.employeeId === targetId);

      setAbsences(myAbsences.reverse());
      setFines(myFines.reverse());
      setAllPayments(myPayments.reverse());
      setOffDayRequests((data.offDayRequests || []).filter(r => r.employeeId === targetId).reverse());
      setFineTypes(data.fineTypes || []);
      const serverStats = await StorageService.getMonthlyStats(targetId, force);
      calculateStats(myRecords, myAbsences, myPayments, myFines, targetEmp?.hourlyRate || 0, targetEmp?.balance || 0, serverStats);
    }
    setLoading(false);
    setRefreshing(false);
  };

  useEffect(() => {
    setLoading(true);
    fetchData();
  }, [selectedEmpId]);

  const calculateStats = (myRecords: AttendanceRecord[], myAbsences: AbsRecord[], myPayments: any[], myFines: Fine[], rate: number, currentBalance: number = 0, serverStats?: any) => {
    const now = new Date();
    const start = startOfMonth(now);
    const end = endOfMonth(now);

    const currentMonthPayments = myPayments.filter(p => {
      const pDate = parseAnyDate(p.paymentDate);
      return !isNaN(pDate.getTime()) && isWithinInterval(pDate, { start, end });
    });

    const currentMonthFines = myFines.filter(f => {
      const fDate = parseAnyDate(f.date);
      return !isNaN(fDate.getTime()) && isWithinInterval(fDate, { start, end });
    });

    const totalPaymentsAmount = currentMonthPayments
      .reduce((sum, p) => sum + p.amount, 0);

    const appliedFines = currentMonthFines.filter(f => f.status === 'approved');
    const totalFinesAmount = appliedFines.reduce((sum, f) => sum + f.amount, 0);

    let totalMinutes = 0;
    let awayMinutes = 0;
    let earnedAmount = 0;
    let workedDays = new Set();
    const dailySessions: any[] = [];

    const currentMonthAbsences = myAbsences.filter(a => {
      const aDate = parseAnyDate(a.startTime);
      return !isNaN(aDate.getTime()) && isWithinInterval(aDate, { start, end });
    });

    awayMinutes = currentMonthAbsences
      .filter(a => a.status === 'approved')
      .reduce((sum, a) => sum + (a.durationMinutes || 0), 0);

    const sortedRecs = [...myRecords].sort((a, b) => (a.timestamp || '').localeCompare(b.timestamp || ''));
    let lastIn: AttendanceRecord | null = null;

    const processSession = (tin: AttendanceRecord, tout: { timestamp: string, type?: string, image?: string, id?: string, hourly_rate?: number }) => {
      const sTime = parseAnyDate(tin.timestamp);
      const eTime = parseAnyDate(tout.timestamp);
      
      const sessionAbs = myAbsences.filter(a => {
        const aStart = a.startTime;
        const aEnd = a.endTime || format(new Date(), 'yyyy-MM-dd HH:mm:ss');
        return aStart < tout.timestamp && aEnd > tin.timestamp;
      });

      let sessionTotalAway = 0;
      let sessionDeduction = 0;

      sessionAbs.forEach(a => {
        const overlapStart = a.startTime > tin.timestamp ? a.startTime : tin.timestamp;
        const overlapEnd = (a.endTime && a.endTime < tout.timestamp) ? a.endTime : tout.timestamp;
        
        const startDt = parseAnyDate(overlapStart);
        const endDt = parseAnyDate(overlapEnd);
        const overlapDur = Math.max(0, (endDt.getTime() - startDt.getTime()) / 60000);

        sessionTotalAway += overlapDur;
        if (a.status === 'approved') {
          sessionDeduction += overlapDur;
        }
      });

      const dur = Math.max(0, (eTime.getTime() - sTime.getTime()) / 60000);

      if (!isNaN(sTime.getTime()) && isWithinInterval(sTime, { start, end })) {
        const sessionMin = (dur - sessionDeduction);
        totalMinutes += sessionMin;
        const sessionRate = tin.hourlyRate || (tout as any).hourlyRate || rate || 0;
        earnedAmount += (sessionMin / 60) * sessionRate;
      }

      dailySessions.push({
        id: tout.id ? `daily-${tout.id}` : `active-${tin.id}`,
        date: formatSafe(tin.timestamp, 'dd.MM.yyyy'),
        checkIn: formatSafe(tin.timestamp, 'HH:mm'),
        checkOut: tout.type === 'check-out' ? formatSafe(tout.timestamp, 'HH:mm') : 'Hali ishda',
        worked: Math.max(0, dur - sessionDeduction) || 0,
        away: sessionTotalAway || 0,
        status: sessionDeduction > 0 ? 'Rad etilgan/Kutilmoqda' : (sessionTotalAway > 0 ? 'Tasdiqlangan' : 'Normal'),
        image: tin.image || tout.image
      });
    };

    sortedRecs.forEach(r => {
      if (r.type === 'check-in') {
        lastIn = r;
        const rDate = parseAnyDate(r.timestamp);
        if (!isNaN(rDate.getTime())) {
          workedDays.add(format(rDate, 'yyyy-MM-dd'));
        }
      } else if (r.type === 'check-out' && lastIn) {
        processSession(lastIn, r);
        lastIn = null;
      }
    });

    // Handle ongoing session
    if (lastIn) {
      processSession(lastIn, { timestamp: format(new Date(), 'yyyy-MM-dd HH:mm:ss') });
    }

    const finalGrossSalary = isNaN(earnedAmount) ? 0 : earnedAmount;
    const finalPayments = isNaN(totalPaymentsAmount) ? 0 : totalPaymentsAmount;
    const finalFines = isNaN(totalFinesAmount) ? 0 : totalFinesAmount;

    setMonthlyStats({
      hours: serverStats?.total_hours ?? (Math.round((totalMinutes / 60) * 10) / 10 || 0),
      salary: serverStats?.salary ?? (Math.round(finalGrossSalary - finalPayments - finalFines) || 0),
      days: serverStats?.total_days ?? (workedDays.size || 0),
      awayMinutes: serverStats?.away_minutes ?? (awayMinutes || 0),
      advances: serverStats?.payments ?? finalPayments,
      fines: serverStats?.fines ?? finalFines,
      dbBalance: serverStats?.db_balance ?? currentBalance,
    });

    setRecords(dailySessions.reverse() as any);
  };

  const openOffDayModal = async () => {
    if (currentEmployee) {
      const res = await StorageService.getOffdayQuota(currentEmployee.id);
      if (res && !res.error) {
        setQuota(res);
      }
    }
    setOffDayModal(true);
  };

  const submitOffDayRequest = async () => {
    if (!requestDate || !reason) {
      alert('Sana va izohni kiriting');
      return;
    }

    const success = await StorageService.saveOffDayRequest({
      employeeId: currentEmployee!.id,
      requestDate,
      reason
    });

    if (success) {
      alert('So\'rov yuborildi');
      setOffDayModal(false);
      setRequestDate('');
      setReason('');
      fetchData(true);
    }
  };

  const renderRecordItem = React.useCallback(({ item }: { item: any }) => (
    <View style={styles.recordItem}>
      <View style={styles.recordMain}>
        <View style={{ flexDirection: 'row', justifyContent: 'space-between', marginBottom: 4 }}>
          <Text style={styles.recordDate}>{item.date}</Text>
          <Text style={styles.recordTime}>{item.checkIn} — {item.checkOut}</Text>
        </View>
        <View style={{ flexDirection: 'row', gap: 15 }}>
          <Text style={styles.sessionStat}>Ish vaqti: <Text style={{ color: '#10B981', fontWeight: 'bold' }}>{Math.floor((item.worked || 0) / 60)}:{(item.worked || 0) % 60 < 10 ? '0' : ''}{(item.worked || 0) % 60}</Text></Text>
          {item.away > 0 && (
            <Text style={styles.sessionStat}>Uzoqlashish: <Text style={{ color: item.status === 'Approved' ? '#10B981' : '#EF4444', fontWeight: 'bold' }}>{item.away}m ({item.status})</Text></Text>
          )}
        </View>
        {item.image && (
          <View style={{ marginTop: 8 }}>
            <Image source={{ uri: item.image }} style={styles.recordImage} />
          </View>
        )}
      </View>
    </View>
  ), []);

  return (
    <View style={[styles.container, { paddingTop: insets.top || 20, paddingHorizontal: 24 }]}>
      <FlatList
        ListHeaderComponent={() => (
          <View>
            <View style={styles.header}>
              <Text style={styles.title}>Hisobotlar</Text>
              {(currentEmployee?.role === 'admin' || currentEmployee?.role === 'superadmin') ? (
                <View style={styles.empPicker}>
                  <Text style={styles.pickerLabel}>Xodimni tanlang:</Text>
                  <FlatList
                    horizontal
                    showsHorizontalScrollIndicator={false}
                    data={employees}
                    keyExtractor={item => item.id}
                    initialNumToRender={10}
                    removeClippedSubviews={true}
                    renderItem={({ item }) => (
                      <TouchableOpacity
                        style={[styles.smallChip, (selectedEmpId || currentEmployee?.id) === item.id && styles.activeSmallChip]}
                        onPress={() => setSelectedEmpId(item.id)}
                      >
                        <Text style={[styles.smallChipText, (selectedEmpId || currentEmployee?.id) === item.id && styles.activeSmallChipText]}>{item.fullName.split(' ')[0]}</Text>
                      </TouchableOpacity>
                    )}
                  />
                </View>
              ) : (
                <View style={styles.empInfoRow}>
                  <Text style={styles.subtitle}>{currentEmployee?.fullName}</Text>
                  <Text style={[styles.subtitle, { color: '#3B82F6', fontWeight: 'bold' }]}>{currentEmployee?.position}</Text>
                </View>
              )}
            </View>

            <View style={styles.statsContainer}>


                  <TouchableOpacity style={[styles.statCard, { backgroundColor: '#FEF2F2', borderColor: '#EF4444', borderWidth: 1 }]} onPress={() => setDetailModal({ visible: true, type: 'advances' })}>
                    <DollarSign size={24} color="#EF4444" />
                    <View style={{ alignItems: 'center' }}>
                      <Text style={[styles.statValue, { color: '#EF4444' }]}>{(monthlyStats.advances || 0).toLocaleString()} UZS</Text>
                      <Text style={styles.statLabel}>To'langan (Avans/Oylik)</Text>
                    </View>
                  </TouchableOpacity>

                  <TouchableOpacity style={[styles.statCard, { backgroundColor: '#FEE2E2', borderColor: '#B91C1C', borderWidth: 1 }]} onPress={() => setDetailModal({ visible: true, type: 'fines' })}>
                    <DollarSign size={24} color="#B91C1C" />
                    <View style={{ alignItems: 'center' }}>
                      <Text style={[styles.statValue, { color: '#B91C1C' }]}>{(monthlyStats.fines || 0).toLocaleString()} UZS</Text>
                      <Text style={styles.statLabel}>Jarimalar(Kechikish)</Text>
                    </View>
                  </TouchableOpacity>

              <TouchableOpacity style={styles.statCard} onPress={() => setDetailModal({ visible: true, type: 'hours' })}>
                <Clock size={24} color="#3B82F6" />
                <View style={{ alignItems: 'center' }}>
                  <Text style={styles.statValue}>{monthlyStats.hours}s</Text>
                  <Text style={styles.statLabel}>Ishlangan vaqt</Text>
                </View>
              </TouchableOpacity>

              <TouchableOpacity style={styles.statCard} onPress={() => setDetailModal({ visible: true, type: 'days' })}>
                <Calendar size={24} color="#8B5CF6" />
                <View style={{ alignItems: 'center' }}>
                  <Text style={styles.statValue}>{monthlyStats.days}</Text>
                  <Text style={styles.statLabel}>Ish kunlari</Text>
                </View>
              </TouchableOpacity>

              <TouchableOpacity style={[styles.statCard, { backgroundColor: '#FFFBEB', borderColor: '#F59E0B', borderWidth: 1 }]} onPress={() => setDetailModal({ visible: true, type: 'away' })}>
                <Info size={24} color="#F59E0B" />
                <View style={{ alignItems: 'center' }}>
                  <Text style={[styles.statValue, { color: '#D97706' }]}>{monthlyStats.awayMinutes} m</Text>
                  <Text style={styles.statLabel}>Ish joyida bo'lmagan</Text>
                </View>
              </TouchableOpacity>
            </View>

            <TouchableOpacity
              style={styles.requestButton}
              onPress={openOffDayModal}
            >
              <Calendar size={20} color="#fff" />
              <Text style={styles.requestButtonText}>Dam olish kuni uchun so'rov</Text>
            </TouchableOpacity>

            <TouchableOpacity
              style={[styles.requestButton, { backgroundColor: '#EF4444', marginTop: 12 }]}
              onPress={() => setShowFineTypesModal(true)}
            >
              <Info size={20} color="#fff" />
              <Text style={styles.requestButtonText}>Jarima turlari</Text>
            </TouchableOpacity>



            {offDayRequests.length > 0 && (
              <View style={[styles.absenceCard, { backgroundColor: '#F3E8FF', borderColor: '#A855F7', borderWidth: 0, shadowColor: '#A855F7', shadowOpacity: 0.1 }]}>
                <Text style={[styles.historyTitle, { color: '#7E22CE' }]}>Dam olish so'rovlari</Text>
                {offDayRequests.slice(0, 5).map(od => (
                  <View key={od.id} style={[styles.absenceRow, { borderBottomColor: '#E9D5FF' }]}>
                    <View>
                      <Text style={[styles.absenceTime, { color: '#7E22CE', fontWeight: 'bold' }]}>{formatSafe(od.requestDate, 'dd.MM.yyyy')}</Text>
                      <Text style={{ fontSize: 11, color: '#9333EA', marginTop: 2 }}>{od.reason}</Text>
                    </View>
                    <View style={[styles.statusTag, 
                      od.status === 'approved' ? styles.statusApproved : 
                      (od.status === 'rejected' ? styles.statusRejected : styles.statusPending)
                    ]}>
                      <Text style={styles.statusTagText}>{od.status === 'approved' ? 'Tasdiqlandi' : (od.status === 'rejected' ? 'Rad etildi' : 'Kutilmoqda')}</Text>
                    </View>
                  </View>
                ))}
              </View>
            )}
            <Text style={styles.historyTitle}>Oxirgi harakatlar</Text>
          </View>
        )}
        data={records}
        keyExtractor={(item) => item.id}
        renderItem={renderRecordItem}
        contentContainerStyle={styles.listContent}
        initialNumToRender={10}
        windowSize={5}
        removeClippedSubviews={true}
        refreshControl={<RefreshControl refreshing={refreshing} onRefresh={() => { setRefreshing(true); fetchData(true); }} />}
        ListEmptyComponent={<Text style={styles.emptyText}>Ma'lumotlar mavjud emas</Text>}
      />

      <Modal
        visible={offDayModal}
        animationType="slide"
        transparent={true}
        onRequestClose={() => setOffDayModal(false)}
      >
        <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : 'height'} style={{ flex: 1 }}>
          <TouchableWithoutFeedback onPress={() => { Keyboard.dismiss(); setOffDayModal(false); }}>
            <View style={styles.modalOverlay}>
              <TouchableWithoutFeedback onPress={Keyboard.dismiss}>
                <View style={styles.modalContent}>
            <Text style={styles.modalTitle}>Dam olish kuni so'rovi</Text>
            {!quota && <Text style={styles.modalSub}>Iltimos, so'rov yubormoqchi bo'lgan sanangizni tanlang</Text>}

            {quota && (
              <View style={[styles.quotaBox, quota.remainingOffDays > 0 ? styles.quotaBoxBlue : styles.quotaBoxRose]}>
                <View style={[styles.quotaIconCircle, quota.remainingOffDays > 0 ? styles.quotaIconBlue : styles.quotaIconRose]}>
                  {quota.remainingOffDays > 0 ? <Info size={16} color="#3B82F6" /> : <Info size={16} color="#EF4444" />}
                </View>
                <View style={{ flex: 1 }}>
                  <Text style={[styles.quotaLabel, quota.remainingOffDays > 0 ? styles.quotaLabelBlue : styles.quotaLabelRose]}>
                    {quota.remainingOffDays > 0 ? 'Mavjud limit' : 'Limit tugagan'}
                  </Text>
                  <Text style={[styles.quotaText, quota.remainingOffDays > 0 ? styles.quotaTextBlue : styles.quotaTextRose]}>
                    {quota.remainingOffDays > 0 
                      ? `Siz ushbu oyda yana ${quota.remainingOffDays} kun bepul dam olishingiz mumkin.` 
                      : 'Sizning bepul limitiz tugagan. Yangi so\'rovlar o\'z hisobingizdan bo\'ladi.'}
                  </Text>
                </View>
              </View>
            )}

            <View style={styles.inputGroup}>
              <Text style={styles.inputLabel}>Sana (YYYY-MM-DD)</Text>
              <TextInput
                style={styles.input}
                value={requestDate}
                onChangeText={setRequestDate}
                placeholder="2026-03-22"
                placeholderTextColor="#9CA3AF"
              />
            </View>

            <View style={styles.inputGroup}>
              <Text style={styles.inputLabel}>Izoh</Text>
              <TextInput
                style={[styles.input, { height: 80, textAlignVertical: 'top' }]}
                value={reason}
                onChangeText={setReason}
                placeholder="Sabab..."
                placeholderTextColor="#9CA3AF"
                multiline
              />
            </View>

            <View style={styles.modalButtons}>
              <TouchableOpacity
                style={[styles.modalButton, styles.cancelButton]}
                onPress={() => setOffDayModal(false)}
              >
                <Text style={styles.cancelButtonText}>Bekor qilish</Text>
              </TouchableOpacity>
              <TouchableOpacity
                style={[styles.modalButton, styles.submitButton]}
                onPress={submitOffDayRequest}
              >
                <Send size={18} color="#fff" />
              </TouchableOpacity>
            </View>
          </View>
              </TouchableWithoutFeedback>
            </View>
          </TouchableWithoutFeedback>
        </KeyboardAvoidingView>
      </Modal>

      <Modal
        visible={detailModal.visible}
        animationType="slide"
        transparent={true}
        onRequestClose={() => setDetailModal({ visible: false, type: null })}
      >
        <View style={styles.modalOverlay}>
          <View style={[styles.modalContent, { maxHeight: '80%' }]}>
            <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginBottom: 20 }}>
              <Text style={styles.modalTitle}>
                {detailModal.type === 'hours' && 'Ishlangan vaqt detali'}
                {detailModal.type === 'salary' && 'Ish haqi detali'}
                {detailModal.type === 'days' && 'Ish kunlari detali'}
                {detailModal.type === 'advances' && 'To\'lovlar detali'}
                {detailModal.type === 'fines' && 'Jarimalar detali'}
                {detailModal.type === 'away' && 'Uzoqlashishlar detali'}
              </Text>
              <TouchableOpacity onPress={() => setDetailModal({ visible: false, type: null })}>
                <Text style={{ color: '#EF4444', fontWeight: 'bold' }}>Yopish</Text>
              </TouchableOpacity>
            </View>

            <FlatList
              data={(() => {
                if (detailModal.type === 'hours') return records;
                if (detailModal.type === 'days') return (records as any[]).filter((v, i, a) => a.findIndex(t => t.date === v.date) === i);
                if (detailModal.type === 'advances') return allPayments;
                if (detailModal.type === 'fines') return fines;
                if (detailModal.type === 'away') return absences;
                if (detailModal.type === 'salary') return records;
                return [];
              })()}
              keyExtractor={(item, index) => item.id || `detail-${index}`}
              renderItem={({ item }) => (
                <View style={{ paddingVertical: 12, borderBottomWidth: 1, borderBottomColor: '#F3F4F6' }}>
                  {detailModal.type === 'hours' && (
                    <View style={{ flexDirection: 'row', justifyContent: 'space-between' }}>
                      <Text style={{ fontWeight: 'bold' }}>{item.date}</Text>
                      <Text style={{ color: '#3B82F6' }}>{Math.floor(item.worked / 60)}h {item.worked % 60}m</Text>
                    </View>
                  )}
                  {detailModal.type === 'days' && (
                    <View style={{ flexDirection: 'row', justifyContent: 'space-between' }}>
                      <Text style={{ fontWeight: 'bold' }}>{item.date}</Text>
                      <Text style={{ color: '#10B981' }}>Ishtirok etdi</Text>
                    </View>
                  )}
                  {detailModal.type === 'advances' && (
                    <View style={{ flexDirection: 'row', justifyContent: 'space-between' }}>
                      <View>
                        <Text style={{ fontWeight: 'bold' }}>{formatSafe(item.paymentDate, 'dd.MM.yyyy HH:mm')}</Text>
                        <Text style={{ fontSize: 11, color: '#6B7280' }}>{item.type === 'advance' ? 'Avans' : (item.type === 'salary' ? 'Oylik' : 'Bonus')} {item.comment ? `(${item.comment})` : ''}</Text>
                      </View>
                      <Text style={{ color: '#EF4444', fontWeight: 'bold' }}>-{item.amount.toLocaleString()} UZS</Text>
                    </View>
                  )}
                  {detailModal.type === 'fines' && (
                    <View style={{ flexDirection: 'row', justifyContent: 'space-between' }}>
                      <View style={{ flex: 1 }}>
                        <Text style={{ fontWeight: 'bold' }}>{formatSafe(item.date, 'dd.MM.yyyy HH:mm')}</Text>
                        <Text style={{ fontSize: 11, color: '#6B7280' }}>{item.reason}</Text>
                      </View>
                      <Text style={{ color: item.status === 'approved' ? '#B91C1C' : '#9CA3AF', fontWeight: 'bold' }}>
                        {item.status === 'approved' ? `-${item.amount.toLocaleString()} UZS` : 'Inkor etilgan'}
                      </Text>
                    </View>
                  )}
                  {detailModal.type === 'away' && (
                    <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' }}>
                      <View>
                        <Text style={{ fontWeight: 'bold' }}>{formatSafe(item.startTime, 'dd.MM HH:mm')}</Text>
                        <Text style={{ fontSize: 11, color: '#6B7280' }}>Daqiqa: {item.durationMinutes}</Text>
                      </View>
                      <Text style={{ color: item.status === 'approved' ? '#10B981' : '#EF4444', fontWeight: 'bold' }}>
                        {item.status === 'approved' ? 'Tasdiqlandi' : 'Rad etildi'}
                      </Text>
                    </View>
                  )}
                  {detailModal.type === 'salary' && (
                    <View style={{ flexDirection: 'row', justifyContent: 'space-between' }}>
                      <Text style={{ fontWeight: 'bold' }}>{item.date}</Text>
                      <Text style={{ color: '#059669', fontWeight: 'bold' }}>{Math.round((item.worked / 60) * (currentEmployee?.hourlyRate || 0)).toLocaleString()} UZS</Text>
                    </View>
                  )}
                </View>
              )}
            />
          </View>
        </View>
      </Modal>
      <Modal
        visible={showFineTypesModal}
        animationType="slide"
        transparent={true}
        onRequestClose={() => setShowFineTypesModal(false)}
      >
        <View style={styles.modalOverlay}>
          <View style={[styles.modalContent, { maxHeight: '80%' }]}>
            <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginBottom: 20 }}>
              <Text style={styles.modalTitle}>Jarima turlari</Text>
              <TouchableOpacity onPress={() => setShowFineTypesModal(false)}>
                <Text style={{ color: '#EF4444', fontWeight: 'bold' }}>Yopish</Text>
              </TouchableOpacity>
            </View>

            <FlatList
              data={fineTypes}
              keyExtractor={(item) => item.id}
              renderItem={({ item }) => (
                <View style={styles.fineTypeItem}>
                  <View style={{ flex: 1 }}>
                    <Text style={styles.fineTypeName}>{item.name}</Text>
                    <Text style={styles.fineTypeDesc}>{item.description}</Text>
                  </View>
                  <Text style={styles.fineTypeAmount}>{item.amount.toLocaleString()} UZS</Text>
                </View>
              )}
              ListEmptyComponent={<Text style={styles.emptyText}>Jarima turlari mavjud emas</Text>}
            />
          </View>
        </View>
      </Modal>
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: '#F3F4F6' },
  header: { marginBottom: 30 },
  title: { fontSize: 28, fontWeight: 'bold', color: '#111827' },
  subtitle: { fontSize: 16, color: '#6B7280', marginTop: 4 },
  statsContainer: { flexDirection: 'row', flexWrap: 'wrap', gap: 12, marginBottom: 30 },
  statCard: { flex: 1, minWidth: '45%', backgroundColor: '#fff', borderRadius: 16, padding: 16, alignItems: 'center', shadowColor: '#000', shadowOpacity: 0.05, shadowRadius: 5, elevation: 2 },
  mainStatCard: { minWidth: '100%', padding: 24, borderWidth: 2, borderColor: '#10B981' },
  statValue: { fontSize: 20, fontWeight: 'bold', color: '#111827', marginTop: 8 },
  salaryValue: { fontSize: 28, color: '#059669' },
  statLabel: { fontSize: 13, color: '#6B7280', marginTop: 4 },
  historyHeader: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 },
  historyTitle: { fontSize: 18, fontWeight: '700', color: '#374151' },
  refreshText: { color: '#3B82F6', fontWeight: '600' },
  listContent: { paddingBottom: 20 },
  recordItem: { backgroundColor: '#fff', borderRadius: 12, padding: 16, flexDirection: 'row', alignItems: 'center', marginBottom: 10 },
  typeBadge: { paddingHorizontal: 10, paddingVertical: 4, borderRadius: 8, marginRight: 15 },
  badgeIn: { backgroundColor: '#D1FAE5' },
  badgeOut: { backgroundColor: '#FEE2E2' },
  badgeText: { fontSize: 12, fontWeight: '600', color: '#065F46' },
  recordMain: { flex: 1 },
  recordTime: { fontSize: 16, fontWeight: '600', color: '#111827' },
  recordDate: { fontSize: 13, color: '#6B7280', fontWeight: '600' },
  sessionStat: { fontSize: 12, color: '#4B5563' },
  recordImage: { width: 45, height: 45, borderRadius: 8, marginLeft: 10, backgroundColor: '#F3F4F6' },
  empPicker: { marginTop: 15 },
  pickerLabel: { fontSize: 13, color: '#6B7280', marginBottom: 8 },
  smallChip: { paddingHorizontal: 12, paddingVertical: 6, borderRadius: 20, backgroundColor: '#fff', marginRight: 8, borderWidth: 1, borderColor: '#E5E7EB' },
  activeSmallChip: { backgroundColor: '#3B82F6', borderColor: '#3B82F6' },
  smallChipText: { fontSize: 12, color: '#4B5563' },
  activeSmallChipText: { color: '#fff', fontWeight: '700' },
  emptyText: { textAlign: 'center', color: '#9CA3AF', marginTop: 40, fontSize: 16 },
  absenceCard: { backgroundColor: '#FFFBEB', borderRadius: 16, padding: 16, marginBottom: 20 },
  absenceRow: { flexDirection: 'row', justifyContent: 'space-between', paddingVertical: 8, borderBottomWidth: 1, borderBottomColor: '#FEF3C7' },
  absenceTime: { fontSize: 14, color: '#92400E' },
  absenceDuration: { fontSize: 14, fontWeight: '700', color: '#D97706' },
  empInfoRow: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginTop: 4 },
  requestButton: {
    backgroundColor: '#3B82F6',
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    padding: 16,
    borderRadius: 16,
    marginBottom: 20,
    gap: 10,
    shadowColor: '#3B82F6',
    shadowOpacity: 0.3,
    shadowRadius: 10,
    elevation: 4
  },
  requestButtonText: { color: '#fff', fontWeight: 'bold', fontSize: 15 },
  modalOverlay: { flex: 1, backgroundColor: 'rgba(0,0,0,0.5)', justifyContent: 'center', padding: 24 },
  modalContent: { backgroundColor: '#fff', borderRadius: 24, padding: 24 },
  modalTitle: { fontSize: 22, fontWeight: 'bold', color: '#111827', marginBottom: 4 },
  modalSub: { fontSize: 14, color: '#6B7280', marginBottom: 24 },
  inputGroup: { marginBottom: 16 },
  inputLabel: { fontSize: 13, fontWeight: 'bold', color: '#374151', marginBottom: 8, marginLeft: 4 },
  input: { backgroundColor: '#F9FAFB', borderWidth: 1, borderColor: '#E5E7EB', borderRadius: 12, padding: 12, color: '#111827', fontSize: 16 },
  modalButtons: { flexDirection: 'row', gap: 12, marginTop: 12 },
  modalButton: { flex: 1, padding: 16, borderRadius: 16, alignItems: 'center', justifyContent: 'center', flexDirection: 'row', gap: 8 },
  cancelButton: { backgroundColor: '#F3F4F6' },
  cancelButtonText: { color: '#4B5563', fontWeight: 'bold' },
  submitButton: { backgroundColor: '#3B82F6' },
  submitButtonText: { color: '#fff', fontWeight: 'bold' },
  statusTag: { paddingHorizontal: 10, paddingVertical: 4, borderRadius: 10 },
  statusPending: { backgroundColor: '#FEF3C7' },
  statusApproved: { backgroundColor: '#D1FAE5' },
  statusRejected: { backgroundColor: '#FEE2E2' },
  statusTagText: { fontSize: 10, fontWeight: 'bold', textTransform: 'uppercase' },
  quotaBox: { flexDirection: 'row', padding: 16, borderRadius: 20, marginBottom: 20, alignItems: 'center', gap: 12, borderWidth: 1 },
  quotaBoxBlue: { backgroundColor: '#EFF6FF', borderColor: '#DBEAFE' },
  quotaBoxRose: { backgroundColor: '#FFF1F2', borderColor: '#FFE4E6' },
  quotaIconCircle: { width: 32, height: 32, borderRadius: 16, alignItems: 'center', justifyContent: 'center' },
  quotaIconBlue: { backgroundColor: '#DBEAFE' },
  quotaIconRose: { backgroundColor: '#FFE4E6' },
  quotaLabel: { fontSize: 11, fontWeight: '800', textTransform: 'uppercase', letterSpacing: 1, marginBottom: 2 },
  quotaLabelBlue: { color: '#1E40AF' },
  quotaLabelRose: { color: '#9F1239' },
  quotaText: { fontSize: 13, fontWeight: '600', lineHeight: 18 },
  quotaTextBlue: { color: '#1E40AF' },
  quotaTextRose: { color: '#9F1239' },
  fineTypeItem: { flexDirection: 'row', alignItems: 'center', backgroundColor: '#F9FAFB', padding: 16, borderRadius: 16, marginBottom: 12, borderWidth: 1, borderColor: '#F3F4F6' },
  fineTypeName: { fontSize: 16, fontWeight: '700', color: '#1F2937' },
  fineTypeDesc: { fontSize: 13, color: '#6B7280', marginTop: 4 },
  fineTypeAmount: { fontSize: 15, fontWeight: 'bold', color: '#EF4444', marginLeft: 16 }
});
