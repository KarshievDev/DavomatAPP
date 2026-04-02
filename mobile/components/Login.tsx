import { StorageService } from '@/scripts/storage';
import { Lock, LogIn, Mail, User } from 'lucide-react-native';
import React, { useState } from 'react';
import { ActivityIndicator, Alert, StyleSheet, Text, TextInput, TouchableOpacity, View, KeyboardAvoidingView, Platform, ScrollView } from 'react-native';

export default function LoginScreen({ onLoginSuccess }: { onLoginSuccess: () => void }) {
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [loading, setLoading] = useState(false);

    const handleLogin = async () => {
        if (!email || !password) return Alert.alert('Xato', 'Email va parolni kiriting');

        setLoading(true);
        try {
            const res = await StorageService.login(email, password);
            if (res === null) {
                Alert.alert('Xato', 'Email yoki parol noto\'g\'ri');
            } else if (typeof res === 'object' && 'error' in res) {
                Alert.alert('Xato', String((res as any).error));
            } else {
                onLoginSuccess();
            }
        } catch (e) {
            Alert.alert('Xato', 'Tizimda xatulik yuz berdi');
        } finally {
            setLoading(false);
        }
    };

    return (
        <KeyboardAvoidingView 
            behavior={Platform.OS === 'ios' ? 'padding' : 'height'}
            style={styles.container}
        >
            <ScrollView contentContainerStyle={styles.scrollContent} showsVerticalScrollIndicator={false}>
                <View style={styles.card}>
                    <View style={styles.header}>
                        <View style={styles.iconCircle}>
                            <User color="#3B82F6" size={32} />
                        </View>
                        <Text style={styles.title}>Kirish</Text>
                        <Text style={styles.subtitle}>Davomat tizimiga xush kelibsiz</Text>
                    </View>

                    <View style={styles.inputGroup}>
                        <View style={styles.inputWrapper}>
                            <Mail color="#9CA3AF" size={20} style={styles.inputIcon} />
                            <TextInput
                                style={styles.input}
                                placeholder="Email"
                                value={email}
                                onChangeText={setEmail}
                                keyboardType="email-address"
                                autoCapitalize="none"
                                placeholderTextColor="#9CA3AF"
                            />
                        </View>
                        <View style={styles.inputWrapper}>
                            <Lock color="#9CA3AF" size={20} style={styles.inputIcon} />
                            <TextInput
                                style={styles.input}
                                placeholder="Parol"
                                value={password}
                                onChangeText={setPassword}
                                secureTextEntry
                                placeholderTextColor="#9CA3AF"
                            />
                        </View>
                    </View>

                    <TouchableOpacity style={styles.button} onPress={handleLogin} disabled={loading}>
                        {loading ? <ActivityIndicator color="#fff" /> : (
                            <>
                                <Text style={styles.buttonText}>Kirish</Text>
                                <LogIn color="#fff" size={20} />
                            </>
                        )}
                    </TouchableOpacity>

                    <Text style={styles.footerText}>Parol kiritilmagan bo'lsa: 12345678</Text>
                </View>
            </ScrollView>
        </KeyboardAvoidingView>
    );
}

const styles = StyleSheet.create({
    container: { flex: 1, backgroundColor: '#F3F4F6' },
    scrollContent: { flexGrow: 1, justifyContent: 'center', padding: 20 },
    card: { backgroundColor: '#fff', borderRadius: 24, padding: 30, shadowColor: '#000', shadowOpacity: 0.1, shadowRadius: 20, elevation: 10 },
    header: { alignItems: 'center', marginBottom: 30 },
    iconCircle: { width: 70, height: 70, borderRadius: 35, backgroundColor: '#EFF6FF', alignItems: 'center', justifyContent: 'center', marginBottom: 15 },
    title: { fontSize: 24, fontWeight: '800', color: '#111827' },
    subtitle: { fontSize: 14, color: '#6B7280', marginTop: 5 },
    inputGroup: { gap: 15, marginBottom: 25 },
    inputWrapper: { flexDirection: 'row', alignItems: 'center', backgroundColor: '#F9FAFB', borderRadius: 12, borderWidth: 1, borderColor: '#E5E7EB', paddingHorizontal: 15 },
    inputIcon: { marginRight: 10 },
    input: { flex: 1, height: 50, fontSize: 16, color: '#111827' },
    button: { height: 55, backgroundColor: '#3B82F6', borderRadius: 12, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 10 },
    buttonText: { color: '#fff', fontSize: 18, fontWeight: '700' },
    footerText: { textAlign: 'center', color: '#9CA3AF', fontSize: 12, marginTop: 20 }
});
