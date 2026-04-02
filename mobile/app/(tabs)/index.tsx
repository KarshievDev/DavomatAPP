import Loader from '@/components/Loader';
import LoginScreen from '@/components/Login';
import { AttendanceRecord, Branch, Employee, StorageService } from '@/scripts/storage';
import { format } from 'date-fns';
import { parseAnyDate, formatSafe } from '@/scripts/dateUtils';
import Constants from 'expo-constants';
import * as ImagePicker from 'expo-image-picker';
import * as Location from 'expo-location';
import { AlertTriangle, Clock, Lock, LogIn, LogOut, LogOut as LogoutIcon, MapPin, Settings, User, Wallet, X } from 'lucide-react-native';
import { useFocusEffect } from '@react-navigation/native';
import React, { useCallback, useEffect, useState } from 'react';
import { Alert, Image, Modal, Platform, ScrollView, StyleSheet, Text, TextInput, TouchableOpacity, View, KeyboardAvoidingView, Linking, Keyboard, TouchableWithoutFeedback } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { initNotifications } from '@/scripts/notificationUtils';
import { APP_CONFIG } from '@/constants/Config';


export default function AttendanceScreen() {
  const insets = useSafeAreaInsets();
  const [location, setLocation] = useState<Location.LocationObject | null>(null);
  const [status, setStatus] = useState<'idle' | 'checked-in' | 'checked-out'>('idle');
  const [lastAction, setLastAction] = useState<AttendanceRecord | null>(null);
  const [currentEmployee, setCurrentEmployee] = useState<Employee | null>(null);
  const [branches, setBranches] = useState<Branch[]>([]);
  const [loading, setLoading] = useState(true);
  const [isAuthenticated, setIsAuthenticated] = useState(false);
  const [showAttendanceForAdmin, setShowAttendanceForAdmin] = useState(false);

  // Password Change
  const [showSettings, setShowSettings] = useState(false);
  const [showProfile, setShowProfile] = useState(false);
  const [isUploading, setIsUploading] = useState(false);
  const [newPass, setNewPass] = useState('');
  const [confirmPass, setConfirmPass] = useState('');

  // Warning
  const [showWarningModal, setShowWarningModal] = useState(false);
  const [warningReason, setWarningReason] = useState('');
  const [warningLoading, setWarningLoading] = useState(false);

  // Check out confirmation
  const [showCheckOutConfirm, setShowCheckOutConfirm] = useState(false);

  useFocusEffect(
    useCallback(() => {
      checkInitialAuth();
    }, [])
  );

  useEffect(() => {
    let watchSubscription: any;

    async function setupWatcher() {
      if (status === 'checked-in' && branches.length > 0) {
        // Foreground watcher - checks all branches
        watchSubscription = await Location.watchPositionAsync(
          {
            accuracy: Location.Accuracy.Balanced,
            timeInterval: 30000,
            distanceInterval: 10,
            // @ts-ignore
            foregroundService: {
              notificationTitle: "Davomat faol",
              notificationBody: "Ish joyida ekanligingiz tekshirilmoqda",
              notificationColor: "#3B82F6",
            }
          },
          async (loc) => {
            let atAnyBranch = false;
            let nearestBranch: Branch | null = null;
            let minDistance = Infinity;

            for (const b of branches) {
              const d = calculateDistance(
                loc.coords.latitude, loc.coords.longitude,
                b.latitude, b.longitude
              );
              if (d < b.radius) atAnyBranch = true;
              if (d < minDistance) {
                minDistance = d;
                nearestBranch = b;
              }
            }

            if (!atAnyBranch && minDistance > 350) { // Increased buffer to 350m to prevent false absence due to GPS jitter
              if (currentEmployee) {
                // Use original branch for record keeping if none nearby
                const originalBranchId = lastAction?.branchId || branches[0].id;
                await StorageService.startAbsence(currentEmployee.id, originalBranchId);
              }
            } else if (atAnyBranch && nearestBranch) {
              await StorageService.endAbsence(nearestBranch.id);
            }
          }
        );
      }
    }

    setupWatcher();
    return () => {
      if (watchSubscription) watchSubscription.remove();
    };
  }, [status, currentEmployee, branches]);

  const checkInitialAuth = async () => {
    setLoading(true);
    try {
      const currentId = await StorageService.getCurrentUser();
      if (currentId) {
        // Try to load cached employees first to avoid logout on network error
        const emps = await StorageService.getEmployees().catch(() => []);
        const me = emps.find(e => e.id === currentId);
        
        if (me) {
          setCurrentEmployee(me);
          setIsAuthenticated(true);
          initNotifications(me); // Initialize notifications with full employee object
          refreshAttendanceStatus(currentId).catch(console.error);
          loadLocationAndBranches().catch(console.error);
        } else {
          // If we have an ID but can't find the employee in the list, 
          // it might be a server error. Don't logout immediately unless we're SURE.
          // For now, if we have an ID and it's a network glitch, we might just show an error.
          if (emps.length > 0) {
              await StorageService.logout();
              setIsAuthenticated(false);
          } else {
              // Network error? Assume user is still logged in if we have an ID.
              setIsAuthenticated(true);
              loadLocationAndBranches().catch(console.error);
          }
        }
      }
    } catch (err) {
      console.error('Auth check error:', err);
    } finally {
      setLoading(false);
    }
  };

  const loadLocationAndBranches = async () => {
    const brs = await StorageService.getBranches();
    setBranches(brs);

    try {
      if (Platform.OS === 'web' && !Constants.isDevice) {
        setLocation({ coords: { latitude: 41.311081, longitude: 69.240562 } } as any);
      } else {
        const { status: foregroundStatus } = await Location.requestForegroundPermissionsAsync();
        if (foregroundStatus === 'granted') {
          // Request background permissions for "Away" tracking
          try {
            await Location.requestBackgroundPermissionsAsync();
          } catch (bgErr) {
            console.warn('Background location permission error:', bgErr);
          }
          
          // Use a faster check first, then high accuracy with timeout
          let lastKnown = await Location.getLastKnownPositionAsync({});
          if (lastKnown) setLocation(lastKnown);

          try {
            const highPrecLocation = await Promise.race([
              Location.getCurrentPositionAsync({ accuracy: Location.Accuracy.High }),
              new Promise<null>((_, reject) => setTimeout(() => reject(new Error('Timeout')), 10000))
            ]);
            if (highPrecLocation) setLocation(highPrecLocation as Location.LocationObject);
          } catch (e) {
             console.warn('High accuracy location failed, using balanced or last known', e);
             if (!lastKnown) {
               const balanced = await Location.getCurrentPositionAsync({ accuracy: Location.Accuracy.Balanced });
               setLocation(balanced);
             }
          }
        } else {
          Alert.alert(
            'Ruxsat kerak',
            'Manzilni aniqlash uchun ruxsat berish kerak. Sozlamalardan ruxsat bera olasiz.',
            [
              { text: 'Bekor qilish' },
              { text: 'Sozlamalar', onPress: () => Linking.openSettings() }
            ]
          );
        }
      }
    } catch (err) {
      console.error('Location error:', err);
    }
  };

  const refreshAttendanceStatus = async (empId: string) => {
    const allRecords = await StorageService.getRecords();
    const empRecords = allRecords.filter(r => r.employeeId === empId);
    if (empRecords.length > 0) {
      let last = empRecords[0]; // Newest first

      // Forgot to check out yesterday
      if (last.type === 'check-in') {
        const lastDateDate = parseAnyDate(last.timestamp);
        if (!isNaN(lastDateDate.getTime())) {
          const lastDate = lastDateDate.toISOString().split('T')[0];
          const today = new Date().toISOString().split('T')[0];
          if (lastDate !== today) {
            last = { ...last, type: 'check-out' }; // Simulate check-out to allow new check-in today
          }
        }
      }

      setLastAction(last);
      setStatus(last.type === 'check-in' ? 'checked-in' : 'checked-out');
    } else {
      setLastAction(null);
      setStatus('idle');
    }
  };

  const calculateDistance = (lat1: number, lon1: number, lat2: number, lon2: number) => {
    const R = 6371e3;
    const φ1 = lat1 * Math.PI / 180;
    const φ2 = lat2 * Math.PI / 180;
    const Δφ = (lat2 - lat1) * Math.PI / 180;
    const Δλ = (lon2 - lon1) * Math.PI / 180;
    const a = Math.sin(Δφ / 2) * Math.sin(Δφ / 2) + Math.cos(φ1) * Math.cos(φ2) * Math.sin(Δλ / 2) * Math.sin(Δλ / 2);
    const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    return R * c;
  };

  const handleAttendance = async (type: 'check-in' | 'check-out') => {
    if (!currentEmployee) return;
    if (branches.length === 0) return Alert.alert('Xato', 'Filiallar yuklanmoqda...');

    try {
      // 📸 1. Kamera ruxsatini so'rash
      const cameraStatus = await ImagePicker.requestCameraPermissionsAsync();
      if (cameraStatus.status !== 'granted') {
        return Alert.alert('Xato', 'Kameraga ruxsat berilmagan. Davomat uchun suratga tushish shart.');
      }

      // 📸 2. Rasm olish — quality=0.4 (tiniqroq rasm)
      const photo = await ImagePicker.launchCameraAsync({
        cameraType: ImagePicker.CameraType.front,
        allowsEditing: false,
        quality: 0.4,
        base64: true,
      });

      if (photo.canceled || !photo.assets || !photo.assets[0].base64) {
        return Alert.alert('Bekor qilindi', 'Suratga tushish bekor qilindi.');
      }

      const base64Image = `data:image/jpeg;base64,${photo.assets[0].base64}`;

      // 📍 3. Joylashuvni aniqlash
      let currentLocation: any;
      if (Platform.OS === 'web' && !Constants.isDevice) {
        currentLocation = { coords: { latitude: branches[0]?.latitude || 0, longitude: branches[0]?.longitude || 0 } };
      } else {
        let { status: locPerm } = await Location.requestForegroundPermissionsAsync();
        if (locPerm !== 'granted') return Alert.alert('Xato', 'Joylashuvga ruxsat kerak');
        currentLocation = await Location.getCurrentPositionAsync({ accuracy: Location.Accuracy.High });
      }

      // 🏢 4. Eng yaqin filialni topish
      let foundBranch: Branch | null = null;
      let minDistance = Infinity;

      for (const b of branches) {
        const d = calculateDistance(
          currentLocation.coords.latitude, currentLocation.coords.longitude,
          b.latitude, b.longitude
        );
        if (d < minDistance) {
          minDistance = d;
          foundBranch = b;
        }
      }

      // 5. Masofa tekshiruvi
      if (type === 'check-in') {
        if (!foundBranch || minDistance > foundBranch.radius) {
          return Alert.alert('Masofa xatosi', 'Hech qaysi filial hududida emassiz. Iltimos, filialga yaqinroq boring.');
        }
      } else {
        if (minDistance > (foundBranch?.radius || 500) + 100) {
          console.warn('Check-out from outside branch radius');
        }
      }

      const branch = foundBranch || branches[0];

      const newRecord: AttendanceRecord = {
        id: Date.now().toString(),
        employeeId: currentEmployee.id,
        branchId: branch.id,
        type,
        timestamp: new Date().toISOString(),
        latitude: currentLocation.coords.latitude,
        longitude: currentLocation.coords.longitude,
        image: base64Image,
      };

      // 6. Serverga yuborish — 20 soniya timeout bilan (muzlab qolmaslik uchun)
      try {
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 20000);

        const response = await fetch(APP_CONFIG.API_URL, {
          method: 'POST',
          headers: { 
            'Content-Type': 'application/json',
            'Accept': 'application/json'
          },
          body: JSON.stringify({ action: 'save_record', ...newRecord }),
          signal: controller.signal,
        });
        clearTimeout(timeoutId);

        const text = await response.text();
        const cleanText = text.trim().replace(/^\uFEFF/, '');
        try {
          const res = JSON.parse(cleanText);
          if (res && res.error) {
            Alert.alert('Xato', res.error);
            return;
          }
        } catch (e: any) {
          console.warn('Server javobi JSON emas, lekin yozilgan bo\'lishi mumkin', cleanText);
          Alert.alert('Xatolik', 'Kutilmagan server javobi: ' + cleanText.substring(0, 50));
          return;
        }
      } catch (fetchErr: any) {
        if (fetchErr.name === 'AbortError') {
          Alert.alert('Ogohlantirish', 'Server sekin ishlamoqda. Davomat qayd etilgan bo\'lishi mumkin.');
        } else {
          Alert.alert('Tarmoq xatosi', 'Internet bilan bog\'laning va qayta urining.');
          return;
        }
      }

      // 7. Chiqishda uzoqlashishni tugatish
      if (type === 'check-out') {
        await StorageService.endAbsence();
      }

      // 8. UI yangilash
      setLastAction(newRecord);
      setStatus(type === 'check-in' ? 'checked-in' : 'checked-out');

      const msg = type === 'check-in' ? 'Ishga keldiniz! ✅' : 'Ishdan ketdingiz! ✅';
      Alert.alert('Muvaffaqiyatli', msg);

    } catch (error: any) {
      console.error('handleAttendance xatosi:', error);
      Alert.alert('Xato', 'Kutilmagan xatolik: ' + (error?.message || 'Qayta urining'));
    } finally {
      setShowCheckOutConfirm(false);
    }
  };

  const handleUpdateProfileImage = async () => {
    try {
      const result = await ImagePicker.launchImageLibraryAsync({
        mediaTypes: ImagePicker.MediaTypeOptions.Images,
        allowsEditing: true,
        aspect: [1, 1],
        quality: 0.5,
        base64: true,
      });

      if (!result.canceled && result.assets[0].base64 && currentEmployee) {
        setIsUploading(true);
        const base64 = `data:image/jpeg;base64,${result.assets[0].base64}`;
        const res = await StorageService.updateProfileImage(currentEmployee.id, base64);
        if (res.success) {
          setCurrentEmployee({ ...currentEmployee, image: res.image_url });
          Alert.alert('Muvaffaqiyatli', 'Profil rasmi yangilandi');
        } else {
          Alert.alert('Xato', res.error || 'Yuklashda xatolik');
        }
      }
    } catch (err) {
      Alert.alert('Xato', 'Rasm tanlashda xatolik yuz berdi');
    } finally {
      setIsUploading(false);
    }
  };

  const handleLogout = async () => {
    await StorageService.logout();
    setIsAuthenticated(false);
    setCurrentEmployee(null);
  };

  const handleUpdatePassword = async () => {
    if (!newPass || !confirmPass) return Alert.alert('Xato', 'Barcha maydonlarni to\'ldiring');
    if (newPass.length < 6) return Alert.alert('Xato', 'Parol kamida 6 ta belgidan iborat bo\'lishi kerak');
    if (newPass !== confirmPass) return Alert.alert('Xato', 'Parollar mos kelmadi');

    if (currentEmployee) {
      await StorageService.updatePassword(currentEmployee.id, newPass);
      setNewPass(''); setConfirmPass('');
      setShowSettings(false);
      Alert.alert('Muvaffaqiyatli', 'Parolingiz o\'zgartirildi');
    }
  };

  const handleSendWarning = async () => {
    if (!warningReason.trim()) return Alert.alert('Xato', 'Sababini yozib qoldiring');
    if (!currentEmployee) return;

    setWarningLoading(true);
    try {
      const response = await fetch(APP_CONFIG.API_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          action: 'save_warning',
          employeeId: currentEmployee.id,
          reason: warningReason
        }),
      });

      const res = await response.json();
      if (res.success) {
        Alert.alert('Muvaffaqiyatli', 'Ogohlantirish yuborildi. Adminlar bu haqda xabardor qilindi.');
        setWarningReason('');
        setShowWarningModal(false);
      } else {
        Alert.alert('Xato', res.error || 'Xatolik yuz berdi');
      }
    } catch (err) {
      console.error(err);
      Alert.alert('Xato', 'Tarmoq xatosi');
    } finally {
      setWarningLoading(false);
    }
  };

  if (loading) {
    return <Loader message="Joylashuv va ma'lumotlar yuklanmoqda..." />;
  }

  if (!isAuthenticated) {
    return <LoginScreen onLoginSuccess={checkInitialAuth} />;
  }

  if ((currentEmployee?.role === 'admin' || currentEmployee?.role === 'superadmin') && !showAttendanceForAdmin) {
    return (
      <View style={[styles.container, { paddingTop: insets.top || 20 }]}>
        <View style={styles.center}>
          <View style={styles.adminIconCircle}>
            <User size={64} color="#3B82F6" />
          </View>
          <Text style={styles.adminMsg}>Siz admin xisobidasiz</Text>
          <Text style={styles.adminName}>{currentEmployee.fullName}</Text>
          <Text style={styles.adminSub}>{currentEmployee.role === 'superadmin' ? 'Superadmin' : 'Admin'}</Text>
          
          <View style={styles.adminTipCard}>
             <Text style={styles.adminTip}>Davomat xodimlarga mo'ljallangan. Boshqaruv uchun pastdagi menyudan "Admin" yoki "Hisobotlar" bo'limiga o'ting.</Text>
          </View>

          <TouchableOpacity style={styles.showAttBtn} onPress={() => setShowAttendanceForAdmin(true)}>
            <Clock color="#3B82F6" size={20} />
            <Text style={styles.showAttBtnText}>Davomat sahifasiga o'tish (Test)</Text>
          </TouchableOpacity>

          <TouchableOpacity style={styles.adminLogoutBtn} onPress={handleLogout}>
            <LogoutIcon color="#EF4444" size={20} />
            <Text style={styles.adminLogoutText}>Tizimdan chiqish</Text>
          </TouchableOpacity>
        </View>
      </View>
    );
  }
  return (
    <ScrollView 
        style={styles.container} 
        contentContainerStyle={[
            styles.scrollContent, 
            { 
                paddingTop: (insets.top || 0) + 20,
                paddingBottom: (insets.bottom || 0) + 40 
            }
        ]} 
        showsVerticalScrollIndicator={false}
    >
      <View style={styles.header}>
        <TouchableOpacity style={styles.userProfileBtn} onPress={() => setShowProfile(true)}>
          {currentEmployee?.image ? (
            <Image source={{ uri: currentEmployee.image }} style={styles.userAvatar} />
          ) : (
            <View style={styles.userAvatarFallback}>
              <User size={24} color="#3B82F6" />
            </View>
          )}
          <View>
            <Text style={styles.welcomeText}>Xush kelibsiz,</Text>
            <Text style={styles.userNameText}>{currentEmployee?.fullName}</Text>
          </View>
        </TouchableOpacity>
        <View style={styles.headerActions}>
          {(currentEmployee?.role === 'admin' || currentEmployee?.role === 'superadmin') && (
            <TouchableOpacity style={[styles.iconBtn, { backgroundColor: '#EFF6FF' }]} onPress={() => setShowAttendanceForAdmin(false)}>
               <User color="#3B82F6" size={20} />
            </TouchableOpacity>
          )}
          <TouchableOpacity style={styles.iconBtn} onPress={() => setShowSettings(true)}>
            <Settings color="#3B82F6" size={20} />
          </TouchableOpacity>
          <TouchableOpacity style={[styles.iconBtn, styles.logoutBtn]} onPress={handleLogout}>
            <LogoutIcon color="#EF4444" size={20} />
          </TouchableOpacity>
        </View>
      </View>

      <Modal
        visible={showProfile}
        animationType="slide"
        transparent
        onRequestClose={() => setShowProfile(false)}
      >
        <View style={styles.modalOverlay}>
          <View style={styles.profileModalContent}>
            <View style={styles.modalHeader}>
              <Text style={styles.modalTitle}>Shaxsiy ma'lumotlar</Text>
              <TouchableOpacity onPress={() => setShowProfile(false)}>
                <X size={24} color="#6B7280" />
              </TouchableOpacity>
            </View>

            <View style={styles.profileDetails}>
              <View style={styles.profileImageSection}>
                <TouchableOpacity onPress={handleUpdateProfileImage} disabled={isUploading}>
                  {currentEmployee?.image ? (
                    <Image source={{ uri: currentEmployee.image }} style={styles.largeAvatar} />
                  ) : (
                    <View style={styles.largeAvatarFallback}>
                      <User size={48} color="#9CA3AF" />
                    </View>
                  )}
                  <View style={styles.editImageBadge}>
                    <Settings size={14} color="#fff" />
                  </View>
                </TouchableOpacity>
                {isUploading && <Text style={styles.uploadingText}>Yuklanmoqda...</Text>}
              </View>

              <View style={styles.profileInfoList}>
                <View style={styles.profileInfoItem}>
                  <User size={20} color="#3B82F6" />
                  <View>
                    <Text style={styles.infoLabel}>F.I.O</Text>
                    <Text style={styles.infoValue}>{currentEmployee?.fullName}</Text>
                  </View>
                </View>
                
                <View style={styles.profileInfoItem}>
                  <MapPin size={20} color="#3B82F6" />
                  <View>
                    <Text style={styles.infoLabel}>Filial</Text>
                    <Text style={styles.infoValue}>
                      {branches.find(b => b.id === currentEmployee?.branchId)?.name || 'Filial biriktirilmagan'}
                    </Text>
                  </View>
                </View>

                <View style={styles.profileInfoItem}>
                  <Clock size={20} color="#3B82F6" />
                  <View>
                    <Text style={styles.infoLabel}>Ish vaqti</Text>
                    <Text style={styles.infoValue}>
                      {currentEmployee?.workStartTime?.substring(0, 5)} - {currentEmployee?.workEndTime?.substring(0, 5)}
                    </Text>
                  </View>
                </View>

                <View style={styles.profileInfoItem}>
                  <View style={{ width: 20, alignItems: 'center' }}>
                    <Text style={{ fontSize: 18 }}>📞</Text>
                  </View>
                  <View>
                    <Text style={styles.infoLabel}>Telefon</Text>
                    <Text style={styles.infoValue}>{currentEmployee?.phone}</Text>
                  </View>
                </View>

                <View style={styles.profileInfoItem}>
                  <View style={{ width: 20, alignItems: 'center' }}>
                    <Text style={{ fontSize: 18 }}>✉️</Text>
                  </View>
                  <View>
                    <Text style={styles.infoLabel}>Email</Text>
                    <Text style={styles.infoValue}>{currentEmployee?.email}</Text>
                  </View>
                </View>
              </View>

              <TouchableOpacity style={styles.settingsProfileBtn} onPress={() => { setShowProfile(false); setShowSettings(true); }}>
                <Lock size={20} color="#fff" />
                <Text style={styles.settingsBtnText}>Parolni o'zgartirish</Text>
              </TouchableOpacity>
            </View>
          </View>
        </View>
      </Modal>


      <Modal
        visible={showSettings}
        animationType="slide"
        transparent
        onRequestClose={() => setShowSettings(false)}
      >
        <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : 'height'} style={{ flex: 1 }}>
          <TouchableWithoutFeedback onPress={() => { Keyboard.dismiss(); setShowSettings(false); }}>
            <View style={styles.modalOverlay}>
              <TouchableWithoutFeedback onPress={Keyboard.dismiss}>
                <View style={styles.modalContent}>
                  <View style={styles.modalHeader}>
                    <Text style={styles.modalTitle}>Parolni o'zgartirish</Text>
                    <TouchableOpacity 
                      style={{ padding: 10, margin: -10, zIndex: 50 }} 
                      hitSlop={{ top: 20, bottom: 20, left: 20, right: 20 }} 
                      onPress={() => setShowSettings(false)}
                    >
                      <X size={24} color="#6B7280" />
                    </TouchableOpacity>
                  </View>

                  <View style={styles.inputLabelRow}>
                    <Lock size={18} color="#6B7280" />
                    <Text style={styles.inputLabel}>Yangi parol:</Text>
                  </View>
                  <TextInput
                    style={styles.modalInput}
                    secureTextEntry
                    placeholder="Kamida 6 belgi"
                    value={newPass}
                    onChangeText={setNewPass}
                    placeholderTextColor="#9CA3AF"
                  />

                  <View style={styles.inputLabelRow}>
                    <Lock size={18} color="#6B7280" />
                    <Text style={styles.inputLabel}>Parolni tasdiqlash:</Text>
                  </View>
                  <TextInput
                    style={styles.modalInput}
                    secureTextEntry
                    placeholder="Qaytadan yozing"
                    value={confirmPass}
                    onChangeText={setConfirmPass}
                    placeholderTextColor="#9CA3AF"
                  />

                  <TouchableOpacity style={styles.updateBtn} onPress={handleUpdatePassword}>
                    <Text style={styles.updateBtnText}>Saqlash</Text>
                  </TouchableOpacity>
                </View>
              </TouchableWithoutFeedback>
            </View>
          </TouchableWithoutFeedback>
        </KeyboardAvoidingView>
      </Modal>

      <TouchableOpacity 
        style={styles.warningCard} 
        onPress={() => setShowWarningModal(true)}
        activeOpacity={0.7}
      >
         <View style={styles.warningHeader}>
             <AlertTriangle color="#F59E0B" size={24} />
             <Text style={styles.warningTitle}>Kechikish haqida ogohlantirish</Text>
         </View>
         <Text style={styles.warningSub}>Agar ishga kech qolsangiz yoki erta ketishingiz kerak bo'lsa, shu yerni bosing</Text>
      </TouchableOpacity>

      <Modal
        visible={showWarningModal}
        animationType="fade"
        transparent
        onRequestClose={() => setShowWarningModal(false)}
      >
        <View style={styles.modalOverlay}>
            <View style={styles.modalContent}>
              <View style={styles.modalHeader}>
                <Text style={styles.modalTitle}>Ogohlantirish</Text>
                <TouchableOpacity onPress={() => setShowWarningModal(false)}>
                  <X size={24} color="#6B7280" />
                </TouchableOpacity>
              </View>

              <Text style={styles.modalSubtitle}>Sababini tushuntiring:</Text>
              <TextInput
                style={[styles.modalInput, { height: 100, textAlignVertical: 'top' }]}
                placeholder="nima sabab tufayli ishga kech qolayapsiz yoki erta ketayapsiz?"
                placeholderTextColor="#9CA3AF"
                multiline
                value={warningReason}
                onChangeText={setWarningReason}
              />

              <TouchableOpacity 
                style={[styles.sendWarningBtn, warningLoading && { opacity: 0.7 }]} 
                onPress={handleSendWarning}
                disabled={warningLoading}
              >
                <Text style={styles.sendWarningText}>
                  {warningLoading ? 'Yuborilmoqda...' : 'Adminni ogohlantirish'}
                </Text>
              </TouchableOpacity>
            </View>
        </View>
      </Modal>

      <View style={styles.statusCard}>
        <View style={styles.infoRow}>
          <User size={20} color="#666" />
          <Text style={styles.infoText}>{currentEmployee?.position}</Text>
        </View>
        <View style={styles.infoRow}>
          <Clock size={20} color="#666" />
          <Text style={styles.infoText}>Vaqt: {format(new Date(), 'HH:mm')}</Text>
        </View>
        <View style={styles.infoRow}>
          <MapPin size={20} color="#666" />
          <Text style={styles.infoText}>{location ? 'GPS faol' : 'GPS kutilmoqda...'}</Text>
        </View>

        {lastAction && (
          <View style={styles.lastActionContainer}>
            <Text style={styles.lastActionTitle}>Oxirgi amal:</Text>
            <Text style={styles.lastActionText}>
              {lastAction.type === 'check-in' ? 'Kelish' : 'Ketish'} - {formatSafe(lastAction.timestamp, 'HH:mm (dd.MM.yyyy)')}
            </Text>
            {lastAction.image && (
              <Image source={{ uri: lastAction.image }} style={styles.lastActionImage} />
            )}
          </View>
        )}
      </View>

      <View style={styles.buttonContainer}>
        <TouchableOpacity
          style={[styles.button, styles.checkInButton, status === 'checked-in' && styles.disabledButton]}
          onPress={() => handleAttendance('check-in')}
          disabled={status === 'checked-in'}
        >
          <LogIn color="#fff" size={24} />
          <Text style={styles.buttonText}>Kirib keldim</Text>
        </TouchableOpacity>

        <TouchableOpacity
          style={[styles.button, styles.checkOutButton, (status === 'checked-out' || status === 'idle') && styles.disabledButton]}
          onPress={() => setShowCheckOutConfirm(true)}
          disabled={status === 'checked-out' || status === 'idle'}
        >
          <LogOut color="#fff" size={24} />
          <Text style={styles.buttonText}>Ketmoqdaman</Text>
        </TouchableOpacity>

        <Modal
          visible={showCheckOutConfirm}
          animationType="fade"
          transparent
          onRequestClose={() => setShowCheckOutConfirm(false)}
        >
          <View style={styles.modalOverlay}>
            <View style={styles.confirmModalContent}>
              <View style={styles.confirmIconContainer}>
                <AlertTriangle size={40} color="#F59E0B" />
              </View>
              <Text style={styles.confirmTitle}>Vazifalarni tekshirdingizmi?</Text>
              <Text style={styles.confirmSub}>Ish kuni yakunlanishidan oldin barcha kunlik vazifalaringiz bajarilganligiga ishonch hosil qiling.</Text>
              
              <View style={styles.confirmButtons}>
                <TouchableOpacity 
                  style={styles.cancelConfirmBtn} 
                  onPress={() => setShowCheckOutConfirm(false)}
                >
                  <Text style={styles.cancelConfirmText}>YO'Q, HALI BOR</Text>
                </TouchableOpacity>
                <TouchableOpacity 
                  style={styles.actionConfirmBtn} 
                  onPress={() => handleAttendance('check-out')}
                >
                  <Text style={styles.actionConfirmText}>HA, TAYYOR</Text>
                </TouchableOpacity>
              </View>
            </View>
          </View>
        </Modal>
      </View>
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: '#F3F4F6' },
  scrollContent: { padding: 24, paddingBottom: 40 },
  header: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginBottom: 30 },
  title: { fontSize: 32, fontWeight: 'bold', color: '#111827' },
  subtitle: { fontSize: 16, color: '#3B82F6', marginTop: 4, fontWeight: '600' },
  headerActions: { flexDirection: 'row', gap: 10 },
  iconBtn: { padding: 10, backgroundColor: '#fff', borderRadius: 12, shadowColor: '#000', shadowOpacity: 0.05, shadowRadius: 5 },
  logoutBtn: { backgroundColor: '#FEE2E2' },
  statusCard: { backgroundColor: '#fff', borderRadius: 20, padding: 20, shadowColor: '#000', shadowOpacity: 0.05, shadowRadius: 10, elevation: 3, marginBottom: 40 },
  infoRow: { flexDirection: 'row', alignItems: 'center', marginBottom: 12 },
  infoText: { marginLeft: 10, fontSize: 16, color: '#374151' },
  lastActionContainer: { marginTop: 15, paddingTop: 15, borderTopWidth: 1, borderTopColor: '#E5E7EB' },
  lastActionTitle: { fontSize: 14, color: '#9CA3AF', marginBottom: 4 },
  lastActionText: { fontSize: 16, fontWeight: '600', color: '#111827' },
  lastActionImage: { width: '100%', height: 150, borderRadius: 12, marginTop: 10, backgroundColor: '#F3F4F6' },
  buttonContainer: { gap: 16 },
  button: { flexDirection: 'row', alignItems: 'center', justifyContent: 'center', padding: 20, borderRadius: 16, gap: 12 },
  checkInButton: { backgroundColor: '#10B981' },
  checkOutButton: { backgroundColor: '#EF4444' },
  disabledButton: { backgroundColor: '#D1D5DB', opacity: 0.7 },
  buttonText: { color: '#fff', fontSize: 18, fontWeight: '700' },
  modalOverlay: { flex: 1, backgroundColor: 'rgba(0,0,0,0.5)', justifyContent: 'center', padding: 20 },
  modalContent: { backgroundColor: '#fff', borderRadius: 24, padding: 24 },
  modalHeader: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginBottom: 20 },
  modalTitle: { fontSize: 20, fontWeight: '800', color: '#111827' },
  inputLabelRow: { flexDirection: 'row', alignItems: 'center', gap: 6, marginBottom: 8 },
  inputLabel: { fontSize: 14, color: '#6B7280', fontWeight: '600' },
  modalInput: { backgroundColor: '#F3F4F6', padding: 14, borderRadius: 12, marginBottom: 16, fontSize: 16, color: '#111827' },
  updateBtn: { backgroundColor: '#3B82F6', padding: 16, borderRadius: 12, alignItems: 'center' },
  updateBtnText: { color: '#fff', fontSize: 16, fontWeight: '800' },
  center: { flex: 1, justifyContent: 'center', alignItems: 'center', padding: 20 },
  adminIconCircle: { width: 120, height: 120, borderRadius: 60, backgroundColor: '#EFF6FF', alignItems: 'center', justifyContent: 'center', marginBottom: 20 },
  adminMsg: { fontSize: 22, fontWeight: '800', color: '#111827', textAlign: 'center' },
  adminName: { fontSize: 18, color: '#3B82F6', fontWeight: '600', marginTop: 5 },
  adminSub: { fontSize: 14, color: '#6B7280', marginBottom: 30 },
  adminTipCard: { backgroundColor: '#fff', padding: 20, borderRadius: 16, marginBottom: 30, shadowColor: '#000', shadowOpacity: 0.05, shadowRadius: 10, elevation: 2 },
  adminTip: { fontSize: 15, color: '#4B5563', textAlign: 'center', lineHeight: 22 },
  adminLogoutBtn: { flexDirection: 'row', alignItems: 'center', gap: 10, padding: 15 },
  adminLogoutText: { color: '#EF4444', fontSize: 16, fontWeight: '700' },
  showAttBtn: { flexDirection: 'row', alignItems: 'center', gap: 10, backgroundColor: '#EFF6FF', paddingVertical: 14, paddingHorizontal: 24, borderRadius: 12, marginBottom: 10 },
  showAttBtnText: { color: '#3B82F6', fontSize: 16, fontWeight: '700' },
  confirmModalContent: { backgroundColor: '#fff', borderRadius: 32, padding: 32, alignItems: 'center', width: '100%' },
  confirmIconContainer: { width: 80, height: 80, borderRadius: 24, backgroundColor: '#FFFBEB', alignItems: 'center', justifyContent: 'center', marginBottom: 20 },
  confirmTitle: { fontSize: 22, fontWeight: '900', color: '#111827', textAlign: 'center', marginBottom: 12 },
  confirmSub: { fontSize: 15, color: '#6B7280', textAlign: 'center', lineHeight: 22, marginBottom: 30 },
  confirmButtons: { flexDirection: 'row', gap: 12, width: '100%' },
  cancelConfirmBtn: { flex: 1, padding: 18, borderRadius: 16, backgroundColor: '#F3F4F6', alignItems: 'center' },
  cancelConfirmText: { color: '#6B7280', fontWeight: '800', fontSize: 14 },
  actionConfirmBtn: { flex: 1, padding: 18, borderRadius: 16, backgroundColor: '#EF4444', alignItems: 'center', shadowColor: '#EF4444', shadowOpacity: 0.2, shadowRadius: 10, elevation: 5 },
  actionConfirmText: { color: '#fff', fontWeight: '800', fontSize: 14 },
  balanceCard: { backgroundColor: '#fff', borderRadius: 24, padding: 24, marginBottom: 20, shadowColor: '#3B82F6', shadowOpacity: 0.1, shadowRadius: 15, elevation: 4, borderLeftWidth: 6, borderLeftColor: '#3B82F6' },
  balanceHeader: { flexDirection: 'row', alignItems: 'center', gap: 10, marginBottom: 8 },
  balanceLabel: { fontSize: 13, color: '#6B7280', fontWeight: '800', textTransform: 'uppercase', letterSpacing: 1 },
  balanceValue: { fontSize: 32, fontWeight: '900', color: '#111827', marginVertical: 4 },
  balanceSub: { fontSize: 12, color: '#9CA3AF', fontWeight: '600' },
  warningCard: { 
    backgroundColor: '#FFFBEB', 
    borderRadius: 24, 
    padding: 24, 
    marginBottom: 20, 
    shadowColor: '#F59E0B', 
    shadowOpacity: 0.1, 
    shadowRadius: 15, 
    elevation: 4, 
    borderLeftWidth: 6, 
    borderLeftColor: '#F59E0B' 
  },
  warningHeader: { flexDirection: 'row', alignItems: 'center', gap: 12, marginBottom: 8 },
  warningTitle: { fontSize: 18, fontWeight: '800', color: '#92400E' },
  warningSub: { fontSize: 14, color: '#B45309', fontWeight: '500', lineHeight: 20 },
  modalSubtitle: { fontSize: 14, color: '#4B5563', marginBottom: 12, fontWeight: '600' },
  sendWarningBtn: { backgroundColor: '#F59E0B', padding: 16, borderRadius: 12, alignItems: 'center', marginTop: 8 },
  sendWarningText: { color: '#fff', fontSize: 16, fontWeight: '800' },
  
  // Profile Styles
  userProfileBtn: { flexDirection: 'row', alignItems: 'center', gap: 12 },
  userAvatar: { width: 50, height: 50, borderRadius: 25, backgroundColor: '#fff' },
  userAvatarFallback: { width: 50, height: 50, borderRadius: 25, backgroundColor: '#fff', alignItems: 'center', justifyContent: 'center', shadowColor: '#000', shadowOpacity: 0.1, shadowRadius: 5 },
  welcomeText: { fontSize: 12, color: '#6B7280', fontWeight: '600' },
  userNameText: { fontSize: 16, fontWeight: '800', color: '#111827' },
  profileModalContent: { backgroundColor: '#fff', borderRadius: 32, padding: 24, width: '100%', maxWidth: 400 },
  profileDetails: { alignItems: 'center', marginTop: 20 },
  profileImageSection: { position: 'relative', marginBottom: 24 },
  largeAvatar: { width: 120, height: 120, borderRadius: 60, backgroundColor: '#F3F4F6' },
  largeAvatarFallback: { width: 120, height: 120, borderRadius: 60, backgroundColor: '#F3F4F6', alignItems: 'center', justifyContent: 'center' },
  editImageBadge: { position: 'absolute', bottom: 5, right: 5, backgroundColor: '#3B82F6', width: 32, height: 32, borderRadius: 16, alignItems: 'center', justifyContent: 'center', borderWidth: 3, borderColor: '#fff' },
  uploadingText: { fontSize: 12, color: '#3B82F6', marginTop: 8, fontWeight: '600' },
  profileInfoList: { width: '100%', gap: 16, marginBottom: 24 },
  profileInfoItem: { flexDirection: 'row', alignItems: 'center', gap: 16, backgroundColor: '#F9FAFB', padding: 16, borderRadius: 20 },
  infoLabel: { fontSize: 11, color: '#9CA3AF', fontWeight: '800', textTransform: 'uppercase', letterSpacing: 0.5 },
  infoValue: { fontSize: 15, color: '#111827', fontWeight: '700' },
  settingsProfileBtn: { flexDirection: 'row', alignItems: 'center', gap: 10, backgroundColor: '#111827', width: '100%', padding: 20, borderRadius: 20, justifyContent: 'center' },
  settingsBtnText: { color: '#fff', fontSize: 16, fontWeight: '800' }
});
